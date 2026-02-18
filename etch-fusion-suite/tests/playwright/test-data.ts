export const MOCK_DISCOVERY_DATA = {
  postTypes: [
    {
      slug: 'post',
      label: 'Post',
      count: 3,
      customFields: 1,
      hasBricks: true,
    },
    {
      slug: 'page',
      label: 'Page',
      count: 2,
      customFields: 1,
      hasBricks: true,
    },
    {
      slug: 'bricks_template',
      label: 'Bricks Template',
      count: 1,
      customFields: 2,
      hasBricks: true,
    },
  ],
  summary: {
    grade: 'green',
    label: 'High convertibility detected (Green)',
    breakdown: [
      { label: 'Bricks entries', value: 6 },
      { label: 'Non-Bricks entries', value: 1 },
      { label: 'Media items', value: 4 },
    ],
  },
  raw: {
    bricksCount: 6,
    gutenbergCount: 1,
    mediaCount: 4,
  },
};

export const MOCK_POST_TYPE_MAPPINGS = {
  minimal: {
    post: 'post',
    page: 'page',
  },
  full: {
    post: 'post',
    page: 'page',
    bricks_template: 'etch_template',
  },
};

export const MOCK_INVALID_MAPPINGS = {
  unavailableTarget: {
    post: 'custom_type',
  },
  missingMapping: {
    post: '',
  },
};

export const MOCK_PROGRESS_SEQUENCE = [
  {
    percentage: 10,
    status: 'running',
    current_phase_name: 'Preparing',
    items_processed: 0,
    items_total: 6,
  },
  {
    percentage: 50,
    status: 'running',
    current_phase_name: 'Posts',
    items_processed: 3,
    items_total: 6,
  },
  {
    percentage: 100,
    status: 'completed',
    current_phase_name: 'Completed',
    items_processed: 6,
    items_total: 6,
  },
];

export const MOCK_RECEIVING_SEQUENCE = [
  {
    status: 'receiving',
    source_site: 'https://bricks.local',
    migration_id: 'mig-1',
    current_phase: 'Preparing',
    items_received: 1,
    last_activity: '2026-02-17 09:00:00',
    is_stale: false,
  },
  {
    status: 'receiving',
    source_site: 'https://bricks.local',
    migration_id: 'mig-1',
    current_phase: 'Posts',
    items_received: 4,
    last_activity: '2026-02-17 09:01:00',
    is_stale: false,
  },
  {
    status: 'completed',
    source_site: 'https://bricks.local',
    migration_id: 'mig-1',
    current_phase: 'Completed',
    items_received: 6,
    last_activity: '2026-02-17 09:02:00',
    is_stale: false,
  },
];

export const MOCK_MIGRATION_ERRORS = {
  invalidMapping: {
    status: 400,
    message: 'Invalid mapping – please choose an available Etch post type',
    code: 'invalid_post_type_mappings',
  },
  missingMapping: {
    status: 400,
    message: 'Missing post type mapping for selected source post type',
    code: 'missing_post_type_mapping',
  },
  recoverableTimeout: {
    status: 200,
    message: 'Timeout while polling migration progress. Please retry.',
    code: 'recoverable_timeout',
  },
  networkInterrupted: {
    status: 503,
    message: 'Connection lost while polling migration status.',
    code: 'network_interrupted',
  },
};
