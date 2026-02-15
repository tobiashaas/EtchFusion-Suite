#!/usr/bin/env node

const { spawnSync } = require('child_process');
const path = require('path');

const CWD = path.resolve(__dirname, '..');
const WP_ENV_ARGS = ['run', 'cli', 'wp'];

function runWpCli(args) {
  const run = process.platform === 'win32'
    ? () => spawnSync('cmd', ['/c', 'npx', 'wp-env', ...WP_ENV_ARGS, ...args], { encoding: 'utf8', cwd: CWD })
    : () => spawnSync('npx', ['wp-env', ...WP_ENV_ARGS, ...args], { encoding: 'utf8', cwd: CWD });
  const result = run();

  if (result.error) {
    throw result.error;
  }

  if (result.status !== 0) {
    throw new Error(result.stderr || result.stdout || `Command failed: wp ${args.join(' ')}`);
  }

  return result.stdout.trim();
}

function generateBricksContent(index) {
  const headingId = `heading-${index}`;
  const textId = `text-${index}`;

  return [
    {
      id: `container-${index}`,
      name: 'container',
      label: `Test Container ${index}`,
      children: [headingId, textId],
      settings: {
        margin: { top: '40px', bottom: '40px' }
      }
    },
    {
      id: headingId,
      name: 'heading',
      label: `Heading ${index}`,
      settings: {
        text: `Test Heading ${index}`,
        tag: 'h2'
      }
    },
    {
      id: textId,
      name: 'text-basic',
      label: `Body Copy ${index}`,
      settings: {
        text: `Lorem ipsum dolor sit amet ${index}.`
      }
    }
  ];
}

function createPosts() {
  for (let i = 1; i <= 10; i += 1) {
    const bricksContent = JSON.stringify(generateBricksContent(i));
    const meta = JSON.stringify({
      _bricks_page_content_2: bricksContent,
      _bricks_editor_mode: 'bricks'
    });

    runWpCli([
      'post',
      'create',
      '--post_type=post',
      `--post_title=Test Post ${i}`,
      '--post_status=publish',
      `--meta_input=${meta}`
    ]);
  }
}

function createPages() {
  const templates = [
    { title: 'Landing Page', heading: 'Build Faster', text: 'Reusable sections for marketing teams.' },
    { title: 'Features', heading: 'Migration Highlights', text: 'We migrate content, styles and global classes.' },
    { title: 'Pricing', heading: 'Simple Pricing', text: 'One migration, unlimited results.' },
    { title: 'About', heading: 'About the Team', text: 'Crafted by Bricks and Etch specialists.' },
    { title: 'Contact', heading: 'Let’s Talk Migration', text: 'Schedule a demo today.' }
  ];

  templates.forEach((template, index) => {
    const bricksContent = JSON.stringify([
      {
        id: `hero-${index}`,
        name: 'container',
        label: `${template.title} Hero`,
        children: [`hero-heading-${index}`, `hero-text-${index}`],
        settings: {
          background: { type: 'color', color: '#0f172a' },
          padding: { top: '120px', bottom: '120px' }
        }
      },
      {
        id: `hero-heading-${index}`,
        name: 'heading',
        label: `${template.title} Heading`,
        settings: {
          text: template.heading,
          tag: 'h1',
          typography: { color: '#f8fafc', font_size: '48px' }
        }
      },
      {
        id: `hero-text-${index}`,
        name: 'text-basic',
        label: `${template.title} Copy`,
        settings: {
          text: template.text,
          typography: { color: '#e2e8f0', font_size: '18px' }
        }
      }
    ]);

    const meta = JSON.stringify({
      _bricks_page_content_2: bricksContent,
      _bricks_builder_data: bricksContent,
      _bricks_template_type: 'page'
    });

    runWpCli([
      'post',
      'create',
      '--post_type=page',
      `--post_title=${template.title}`,
      '--post_status=publish',
      `--meta_input=${meta}`
    ]);
  });
}

function createGlobalClasses() {
  const classes = [
    {
      id: 'btn-primary',
      name: 'Primary Button',
      settings: {
        color: '#ffffff',
        background: '#2563eb',
        padding: { top: '12px', right: '24px', bottom: '12px', left: '24px' },
        border_radius: '8px'
      }
    },
    {
      id: 'section-spacing',
      name: 'Section Spacing',
      settings: {
        margin: { top: '80px', bottom: '80px' }
      }
    }
  ];

  const optionValue = JSON.stringify(classes);
  runWpCli(['option', 'update', 'bricks_global_classes', optionValue]);
}

function importMedia() {
  try {
    runWpCli([
      'media',
      'import',
      '/var/www/html/wp-content/plugins/etch-fusion-suite/test-images/*',
      '--skip-copy'
    ]);
  } catch (error) {
    console.warn('⚠ Media import skipped:', error.message);
  }
}

async function createTestContent() {
  console.log('▶ Creating test posts...');
  createPosts();

  console.log('▶ Creating test pages...');
  createPages();

  console.log('▶ Creating global classes...');
  createGlobalClasses();

  console.log('▶ Importing media (if available)...');
  importMedia();

  console.log('✓ Test content ready on Bricks instance');
}

if (require.main === module) {
  createTestContent().catch((error) => {
    console.error('✗ Failed to create test content:', error.message);
    process.exit(1);
  });
}

module.exports = createTestContent;
