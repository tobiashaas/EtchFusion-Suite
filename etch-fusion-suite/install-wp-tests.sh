#!/usr/bin/env bash

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}
FORCE_REINSTALL_INPUT=${7-false}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo $TMPDIR | sed -e "s/\/$//")

# Check if /tmp has enough space, fallback to alternative if needed
if [ "$TMPDIR" = "/tmp" ]; then
	# Check available space in /tmp (in KB)
	AVAILABLE_SPACE=$(df /tmp | awk 'NR==2 {print $4}')
	# Need at least 1GB (1024000 KB) for WordPress installation
	if [ "$AVAILABLE_SPACE" -lt 1024000 ]; then
		echo "Warning: /tmp has insufficient space (${AVAILABLE_SPACE}KB). Using alternative directory."
		TMPDIR="${HOME}/tmp/wordpress-tests"
		mkdir -p "$TMPDIR"
	fi
fi

WP_TESTS_DIR=${WP_TESTS_DIR-${TMPDIR}/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-${TMPDIR}/wordpress}

is_truthy() {
	local value="$(echo "$1" | tr '[:upper:]' '[:lower:]')"
	case "$value" in
		y|yes|true|1|on)
			return 0
			;;
		*)
			return 1
			;;
	esac
}

AUTO_REINSTALL=false
if is_truthy "$FORCE_REINSTALL_INPUT"; then
	AUTO_REINSTALL=true
fi
if is_truthy "${WP_TESTS_FORCE_REINSTALL:-}"; then
	AUTO_REINSTALL=true
fi
if [ -n "${CI:-}" ] && [ "${CI}" != "false" ]; then
	AUTO_REINSTALL=true
fi

download() {
    local url="$1"
    local destination="$2"

    if [ -f "$destination" ]; then
        rm -f "$destination"
    fi

    if [ `which curl` ]; then
        curl -LsS "$url" -o "$destination"
    elif [ `which wget` ]; then
        wget -nv -O "$destination" "$url"
    else
        echo "Error: Neither curl nor wget is installed."
        exit 1
    fi
}

ensure_tool() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Error: Required tool '$1' is not installed."
        exit 1
    fi
}

ensure_required_tools() {
    ensure_tool tar
}

VERSIONS_JSON="$TMPDIR/wp-versions.json"

ensure_required_tools

WORK_DIR=$(mktemp -d "$TMPDIR/efs-wp-tests-XXXXXX")
trap 'rm -rf "$WORK_DIR"' EXIT

fetch_versions_manifest() {
    if [ ! -f "$VERSIONS_JSON" ]; then
        download https://api.wordpress.org/core/version-check/1.7/ "$VERSIONS_JSON"
    fi
}

resolve_latest_version() {
    fetch_versions_manifest
    grep -o '"version":"[^"\n]*' "$VERSIONS_JSON" | sed 's/"version":"//' | head -1
}

resolve_latest_for_major() {
    local major="$1"
    fetch_versions_manifest
    local major_escaped=$(echo "$major" | sed 's/\./\\./g')
    grep -o '"version":"'$major_escaped'[^"\n]*' "$VERSIONS_JSON" | sed 's/"version":"//' | head -1
}

WP_RESOLVED_VERSION="$WP_VERSION"
WP_TESTS_REF_TYPE="refs/tags"
WP_TESTS_REF_VALUE="$WP_VERSION"
WP_TESTS_RAW_REF="$WP_TESTS_REF_VALUE"

if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
    WP_RESOLVED_VERSION="trunk"
    WP_TESTS_REF_TYPE="refs/heads"
    WP_TESTS_REF_VALUE="trunk"
    WP_TESTS_RAW_REF="trunk"
elif [[ $WP_VERSION == 'latest' ]]; then
    WP_RESOLVED_VERSION=$(resolve_latest_version)
    WP_TESTS_REF_VALUE="$WP_RESOLVED_VERSION"
    WP_TESTS_RAW_REF="$WP_RESOLVED_VERSION"
elif [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
    WP_RESOLVED_VERSION=$(resolve_latest_for_major "$WP_VERSION")
    if [[ -z "$WP_RESOLVED_VERSION" ]]; then
        WP_RESOLVED_VERSION="$WP_VERSION"
    fi
    WP_TESTS_REF_VALUE="$WP_RESOLVED_VERSION"
    WP_TESTS_RAW_REF="$WP_RESOLVED_VERSION"
elif [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+\.(beta|RC)[0-9]+$ ]]; then
    WP_BRANCH=${WP_VERSION%-*}
    WP_RESOLVED_VERSION=$(resolve_latest_for_major "$WP_BRANCH")
    WP_TESTS_REF_TYPE="refs/heads"
    WP_TESTS_REF_VALUE="$WP_BRANCH"
    WP_TESTS_RAW_REF="$WP_BRANCH"
elif [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    WP_RESOLVED_VERSION="$WP_VERSION"
    WP_TESTS_REF_VALUE="$WP_VERSION"
    WP_TESTS_RAW_REF="$WP_VERSION"
fi

if [[ -z "$WP_RESOLVED_VERSION" ]]; then
    echo "Unable to resolve WordPress version for '$WP_VERSION'"
    exit 1
fi

if [[ $WP_TESTS_REF_TYPE == 'refs/heads' ]]; then
    WP_TESTS_ARCHIVE_REF_PATH="refs/heads/${WP_TESTS_REF_VALUE}"
else
    WP_TESTS_ARCHIVE_REF_PATH="refs/tags/${WP_TESTS_REF_VALUE}"
fi

set -e

install_wp() {

	if [ -d $WP_CORE_DIR ]; then
		return;
	fi

	mkdir -p $WP_CORE_DIR

	if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
		ensure_tool git
		local GIT_CLONE_DIR="$WORK_DIR/wordpress-develop"
		git clone --depth=1 https://github.com/WordPress/wordpress-develop.git "$GIT_CLONE_DIR"
		cp -R "$GIT_CLONE_DIR/src/." "$WP_CORE_DIR"
	else
		local ARCHIVE_NAME="wordpress-${WP_RESOLVED_VERSION}.tar.gz"
		local CORE_ARCHIVE="$WORK_DIR/$ARCHIVE_NAME"
		download https://wordpress.org/${ARCHIVE_NAME} "$CORE_ARCHIVE"
		tar --strip-components=1 -zxf "$CORE_ARCHIVE" -C $WP_CORE_DIR 2>/dev/null || tar --strip-components=1 -zxf "$CORE_ARCHIVE" -C $WP_CORE_DIR
	fi

	if [ ! -f $WP_CORE_DIR/wp-content/db.php ]; then
		download https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php $WP_CORE_DIR/wp-content/db.php
	fi
}

install_test_suite() {
	# portable in-place argument for both GNU sed and Mac OSX sed
	if [[ $(uname -s) == 'Darwin' ]]; then
		local ioption='-i.bak'
	else
		local ioption='-i'
	fi

	# set up testing suite if directories are missing
	local NEED_TEST_SETUP=0
	if [ ! -d "$WP_TESTS_DIR" ]; then
		NEED_TEST_SETUP=1
	elif [ ! -d "$WP_TESTS_DIR/includes" ] || [ ! -d "$WP_TESTS_DIR/data" ]; then
		NEED_TEST_SETUP=1
	fi

	if [ $NEED_TEST_SETUP -eq 1 ]; then
		mkdir -p "$WP_TESTS_DIR"
		rm -rf "$WP_TESTS_DIR/includes" "$WP_TESTS_DIR/data"

		local TESTS_ARCHIVE="wordpress-develop-${WP_TESTS_REF_VALUE}.tar.gz"
		download https://github.com/WordPress/wordpress-develop/archive/${WP_TESTS_ARCHIVE_REF_PATH}.tar.gz "$WORK_DIR/$TESTS_ARCHIVE"

		local ARCHIVE_ROOT
		ARCHIVE_ROOT=$(tar -tzf "$WORK_DIR/$TESTS_ARCHIVE" | head -1 | cut -f1 -d"/")
		tar -zxf "$WORK_DIR/$TESTS_ARCHIVE" -C "$WORK_DIR"

		local TESTS_SRC="$WORK_DIR/${ARCHIVE_ROOT}/tests/phpunit"
		if [ ! -d "$TESTS_SRC" ]; then
			echo "Unable to locate PHPUnit tests in archive"
			exit 1
		fi

		cp -R "$TESTS_SRC/includes" "$WP_TESTS_DIR"
		cp -R "$TESTS_SRC/data" "$WP_TESTS_DIR"
	fi

	if [ ! -f $WP_TESTS_DIR/wp-tests-config.php ]; then
		download https://raw.githubusercontent.com/WordPress/wordpress-develop/${WP_TESTS_RAW_REF}/wp-tests-config-sample.php "$WP_TESTS_DIR"/wp-tests-config.php
		# remove all forward slashes in the end
		WP_CORE_DIR=$(echo $WP_CORE_DIR | sed "s:/\+$::")
		sed $ioption "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s:__DIR__ . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR"/wp-tests-config.php
	fi

}

recreate_db() {
	shopt -s nocasematch
	if [[ $1 =~ ^(y|yes)$ ]]
	then
		if [ -z "$DB_PASS" ]; then
		mysqladmin drop $DB_NAME -f --user="$DB_USER"$EXTRA
	else
		mysqladmin drop $DB_NAME -f --user="$DB_USER" --password="$DB_PASS"$EXTRA
	fi
		create_db
		echo "Recreated the database ($DB_NAME)."
	else
		echo "Leaving the existing database ($DB_NAME) in place."
	fi
	shopt -u nocasematch
}

create_db() {
	if [ -z "$DB_PASS" ]; then
		mysqladmin create $DB_NAME --user="$DB_USER"$EXTRA
	else
		mysqladmin create $DB_NAME --user="$DB_USER" --password="$DB_PASS"$EXTRA
	fi
}

install_db() {

	if [ ${SKIP_DB_CREATE} = "true" ]; then
		return 0
	fi

	# parse DB_HOST for port or socket references
	local PARTS=(${DB_HOST//\:/ })
	local DB_HOSTNAME=${PARTS[0]};
	local DB_SOCK_OR_PORT=${PARTS[1]};
	local EXTRA=""

	if ! [ -z $DB_HOSTNAME ] ; then
		if [ $(echo $DB_SOCK_OR_PORT | grep -e '^[0-9]\{1,\}$') ]; then
			EXTRA=" --host=$DB_HOSTNAME --port=$DB_SOCK_OR_PORT --protocol=tcp"
		elif ! [ -z $DB_SOCK_OR_PORT ] ; then
			EXTRA=" --socket=$DB_SOCK_OR_PORT"
		elif ! [ -z $DB_HOSTNAME ] ; then
			EXTRA=" --host=$DB_HOSTNAME --protocol=tcp"
		fi
	fi

	# create database
	local MYSQL_PWD_FLAG=""
	[ -n "$DB_PASS" ] && MYSQL_PWD_FLAG="--password=$DB_PASS"
	if [ $(mysql --user="$DB_USER" $MYSQL_PWD_FLAG$EXTRA --execute='show databases;' | grep ^$DB_NAME$) ]
	then
		echo "Reinstalling will delete the existing test database ($DB_NAME)"
		if $AUTO_REINSTALL; then
			recreate_db "y"
		else
			read -p 'Are you sure you want to proceed? [y/N]: ' DELETE_EXISTING_DB
			recreate_db $DELETE_EXISTING_DB
		fi
	else
		create_db
	fi
}

install_wp
install_test_suite
install_db
