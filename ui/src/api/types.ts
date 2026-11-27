// Shapes of the JSON API (plugin/src/UserApi.php, AdminApi.php). Keys mirror the PHP
// side, which stores and returns snake_case.

export type Environment = 'testing' | 'production'

export type DeployStatus = 'success' | 'running' | 'queued' | 'failed' | 'failed_after_activation'

export interface Blocked {
  code: string
  title: string
  message: string
}

export interface Boot {
  area: 'user' | 'admin'
  base: string
  api: string
  csrf: string
  version: string
  user: string
  server_time: number
  blocked: Blocked | null
}

export interface Site {
  id: string
  hostname: string
  base_path: string
  web_dir: string
  environment: Environment
  repo: string
  branch: string
  auto_deploy: boolean
  php: string
  shared_paths: string[]
  keep_releases: number
  script?: string
  created_at: number
  source_label: string
  docroot_path: string
  current_path: string
}

/** A commit or release on GitHub (GitHub::commitTarget / releaseTarget). */
export interface Target {
  id: string
  type: 'commit' | 'release'
  ref: string
  sha: string | null
  label: string
  message: string
  author: string
  url: string | null
  date: number | null
  prerelease: boolean
}

/**
 * One deployment or rollback in a site's history. Entries come from state files written by
 * different code paths (and versions), so everything but id, status and started_at may be
 * missing; the UI must cope.
 */
export interface HistoryEntry {
  id: string
  type: 'deploy' | 'rollback'
  release: string | null
  trigger?: 'auto' | 'manual'
  environment?: Environment
  target_id?: string
  ref?: string
  label: string
  sha?: string | null
  message?: string
  author?: string
  url?: string | null
  status: DeployStatus
  activated: boolean
  error: string | null
  started_at: number
  finished_at: number | null
}

export interface QueuedAction {
  action: 'deploy' | 'deploy_ref' | 'rollback'
  ref?: string
  release?: string
  message?: string
  requested_at: number
}

export interface DocrootResult {
  ok: boolean
  message: string
  checked_at: number
}

export interface SiteStatus {
  site: Site
  current_id: string | null
  current: HistoryEntry | null
  last: HistoryEntry | null
  remote: Target | null
  queued: QueuedAction | null
  running: { id: string; started_at: number } | null
  update_available: boolean
  docroot: DocrootResult | null
  last_poll: number | null
  poll_error: string | null
}

export interface ReleaseOnDisk {
  id: string
  entry: HistoryEntry | null
  current: boolean
  can_activate: boolean
}

export interface SiteDetail extends SiteStatus {
  history: HistoryEntry[]
  releases: ReleaseOnDisk[]
  poll_interval: number
  deploy_timeout: number
  env_shared: boolean
  env_exists: boolean
  has_token: boolean
  docroot_rule: DocrootRule | null
}

/** Custom HTTPD Configurations lines that set the document root (Docroot::httpdRule). */
export interface DocrootRule {
  /** The DirectAdmin domain whose configuration takes the lines. */
  domain: string
  /** The subdomain label when the site is on a subdomain of that domain. */
  sub: string | null
  snippet: string
}

export interface Overview {
  sites: SiteStatus[]
  has_token: boolean
  poll_interval: number
}

export interface Option {
  key: string
  label: string
}

export interface SiteFormData {
  site: Site
  is_new: boolean
  domains: string[]
  php_versions: Option[]
  max_keep: number
  max_shared: number
  site_token: string | null
  has_default_token: boolean
  home: string
  default_script: string
  /** The saved script is an earlier default, unchanged (it lacks the Node.js check). */
  outdated_script: boolean
  node: NodeStatus
}

/** Node.js and npm as a deploy script finds them on the PATH it starts with. */
export interface NodeStatus {
  /** `supported`: at least `min_major` (false when node does not report its version). */
  node: { path: string; version: string | null; supported: boolean } | null
  npm: { path: string; version: string | null } | null
  path: string
  /**
   * A version manager installed for the account (nvm, Volta, fnm): what was found, and a command
   * to run once over SSH (`ssh`) or a line to add to the deploy script.
   */
  hint: { text: string; line: string; ssh: boolean } | null
  /** Oldest Node.js major version $REQUIRE_NODE() accepts. */
  min_major: number
  /** The administrator's override (comes first on PATH); `usable`: the Node.js file can be run. */
  admin: { node: string | null; npm: string | null; usable: boolean }
}

export interface RepoCheck {
  check: {
    repo: string
    private: boolean
    default_branch: string | null
    branch_head: Target | null
    latest_release?: Target | null
  }
  message: string
}

export interface Refs {
  site: Site
  kind: 'release' | 'commit'
  items: Target[]
  current_target: string | null
}

export interface EnvFile {
  site: Site
  path: string
  content: string
  exists: boolean
  valid_utf8: boolean
  shared: boolean
  max_bytes: number
}

export interface LogChunk {
  site: Pick<Site, 'id' | 'hostname' | 'environment'>
  entry: HistoryEntry | null
  chunk: string
  offset: number
  more: boolean
  truncated: boolean
  reset: boolean
  running: boolean
}

export interface UserSettings {
  token: string | null
  has_token: boolean
  server: { poll_interval: number; deploy_timeout: number; max_keep_releases: number }
  php_versions: Option[]
  composer: string | null
}

export interface Message {
  message: string
}

// ------------------------------------------------------------------ admin

export interface AdminSiteStatus {
  id: string
  hostname: string
  environment: Environment
  repo: string
  source: string
  auto_deploy: boolean
  current_release: string | null
  current_label: string | null
  current_sha: string | null
  last_status: DeployStatus | null
  last_at: number | null
  last_error: string | null
  last_poll: number | null
  poll_error: string | null
}

export interface AdminUserStatus {
  user: string
  updated_at: number | null
  error: string | null
  sites: AdminSiteStatus[]
}

export interface AdminSettings {
  poll_interval: number
  keep_releases: number
  max_keep_releases: number
  deploy_timeout: number
  reload_fpm: boolean
  max_parallel: number
  access_policy: string
  /** Node.js for deploy scripts; '' = from PATH. */
  node_path: string
  /** npm for deploy scripts; '' = next to node_path, else from PATH. */
  npm_path: string
}

export interface AccessOverride {
  user: string
  value: 'allow' | 'deny'
}

export interface AdminOverview {
  users: AdminUserStatus[]
  dispatcher: { last_run?: number; eligible_users?: number; [k: string]: unknown } | null
  cron_stale_after: number
  /** Optional rows (Node.js, npm) are a warning when not ok, not a problem. */
  diagnostics: { label: string; value: string; ok: boolean; optional: boolean }[]
  writable: boolean
  /** Account DirectAdmin runs admin pages as (the logged-in admin). */
  identity: string
  /** Account that owns the data directory: the main admin account after install.sh. */
  data_owner: string | null
  /** How to make an unwritable data directory writable: run install.sh, or log in as data_owner. */
  permission_fix: 'install' | 'main_admin' | null
  /** Whether this account can read the status files root writes for the overview. */
  status_readable: boolean
  install_command: string
  version: string
  /** Oldest Node.js major version deploy scripts accept. */
  node_min_major: number
  settings: AdminSettings
  policies: Option[]
  overrides: AccessOverride[]
}
