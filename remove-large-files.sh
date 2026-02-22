#!/bin/sh
git ls-files -z | while IFS= read -r -d '' f; do
  case "$f" in
    *local-backup*|*bricks_state*|*state-snapshot*) git rm -rf --cached --ignore-unmatch "$f" ;;
  esac
done
