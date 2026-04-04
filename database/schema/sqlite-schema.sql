CREATE TABLE api_tokens (
	id CHAR(26) NOT NULL, 
	user_id CHAR(26) NOT NULL, 
	organization_id CHAR(26) NOT NULL, 
	name VARCHAR(255) NOT NULL, 
	token_prefix VARCHAR(32) NOT NULL, 
	token_hash VARCHAR(255) NOT NULL, 
	last_used_at TIMESTAMP, 
	expires_at TIMESTAMP, 
	abilities JSON, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	allowed_ips JSON, 
	CONSTRAINT api_tokens_pkey PRIMARY KEY (id), 
	CONSTRAINT api_tokens_token_prefix_unique UNIQUE (token_prefix)
);
CREATE TABLE audit_logs (
	id CHAR(26) NOT NULL, 
	organization_id CHAR(26) NOT NULL, 
	user_id CHAR(26), 
	action VARCHAR(255) NOT NULL, 
	subject_type VARCHAR(255), 
	subject_id CHAR(26), 
	old_values JSON, 
	new_values JSON, 
	ip_address VARCHAR(255), 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT audit_logs_pkey PRIMARY KEY (id)
);
CREATE TABLE backup_configurations (
	id CHAR(26) NOT NULL, 
	user_id CHAR(26) NOT NULL, 
	name VARCHAR(160) NOT NULL, 
	provider VARCHAR(32) NOT NULL, 
	config TEXT NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT backup_configurations_pkey PRIMARY KEY (id)
);
CREATE TABLE cache (
	"key" VARCHAR(255) NOT NULL, 
	value TEXT NOT NULL, 
	expiration BIGINT NOT NULL, 
	CONSTRAINT cache_pkey PRIMARY KEY ("key")
);
CREATE TABLE cache_locks (
	"key" VARCHAR(255) NOT NULL, 
	owner VARCHAR(255) NOT NULL, 
	expiration BIGINT NOT NULL, 
	CONSTRAINT cache_locks_pkey PRIMARY KEY ("key")
);
CREATE TABLE coming_soon_signups (
	id CHAR(26) NOT NULL, 
	email VARCHAR(254) NOT NULL, 
	source VARCHAR(120), 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT coming_soon_signups_pkey PRIMARY KEY (id), 
	CONSTRAINT coming_soon_signups_email_unique UNIQUE (email)
);
CREATE TABLE failed_jobs (
	id BIGINT NOT NULL, 
	uuid VARCHAR(255) NOT NULL, 
	connection TEXT NOT NULL, 
	queue TEXT NOT NULL, 
	payload TEXT NOT NULL, 
	exception TEXT NOT NULL, 
	failed_at TIMESTAMP NOT NULL, 
	CONSTRAINT failed_jobs_pkey PRIMARY KEY (id), 
	CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid)
);
CREATE TABLE firewall_rule_templates (
	id CHAR(26) NOT NULL, 
	organization_id CHAR(26) NOT NULL, 
	server_id CHAR(26), 
	name VARCHAR(160) NOT NULL, 
	description VARCHAR(500), 
	rules JSON NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT firewall_rule_templates_pkey PRIMARY KEY (id)
);
CREATE TABLE incident_updates (
	id CHAR(26) NOT NULL, 
	incident_id CHAR(26) NOT NULL, 
	user_id CHAR(26), 
	body TEXT NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT incident_updates_pkey PRIMARY KEY (id)
);
CREATE TABLE incidents (
	id CHAR(26) NOT NULL, 
	status_page_id CHAR(26) NOT NULL, 
	user_id CHAR(26) NOT NULL, 
	title VARCHAR(255) NOT NULL, 
	impact VARCHAR(255) DEFAULT 'minor' NOT NULL, 
	state VARCHAR(255) DEFAULT 'investigating' NOT NULL, 
	started_at TIMESTAMP NOT NULL, 
	resolved_at TIMESTAMP, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT incidents_pkey PRIMARY KEY (id)
);
CREATE TABLE insight_digest_queue (
	id BIGINT NOT NULL, 
	insight_finding_id BIGINT NOT NULL, 
	organization_id CHAR(26) NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT insight_digest_queue_pkey PRIMARY KEY (id), 
	CONSTRAINT insight_digest_queue_insight_finding_id_unique UNIQUE (insight_finding_id)
);
CREATE TABLE insight_findings (
	id BIGINT NOT NULL, 
	server_id CHAR(26) NOT NULL, 
	site_id CHAR(26), 
	insight_key VARCHAR(80) NOT NULL, 
	dedupe_hash VARCHAR(64) NOT NULL, 
	status VARCHAR(24) DEFAULT 'open' NOT NULL, 
	severity VARCHAR(24) DEFAULT 'warning' NOT NULL, 
	title VARCHAR(255) NOT NULL, 
	body TEXT, 
	meta JSON, 
	detected_at TIMESTAMP, 
	resolved_at TIMESTAMP, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	correlation JSON, 
	team_id CHAR(26), 
	CONSTRAINT insight_findings_pkey PRIMARY KEY (id)
);
CREATE TABLE insight_health_snapshots (
	id BIGINT NOT NULL, 
	server_id CHAR(26) NOT NULL, 
	score SMALLINT NOT NULL, 
	counts JSON, 
	captured_at TIMESTAMP NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT insight_health_snapshots_pkey PRIMARY KEY (id)
);
CREATE TABLE insight_settings (
	id BIGINT NOT NULL, 
	settingsable_type VARCHAR(255) NOT NULL, 
	settingsable_id CHAR(26) NOT NULL, 
	enabled_map JSON, 
	parameters JSON, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT insight_settings_pkey PRIMARY KEY (id)
);
CREATE TABLE job_batches (
	id VARCHAR(255) NOT NULL, 
	name VARCHAR(255) NOT NULL, 
	total_jobs INTEGER NOT NULL, 
	pending_jobs INTEGER NOT NULL, 
	failed_jobs INTEGER NOT NULL, 
	failed_job_ids TEXT NOT NULL, 
	options TEXT, 
	cancelled_at INTEGER, 
	created_at INTEGER NOT NULL, 
	finished_at INTEGER, 
	CONSTRAINT job_batches_pkey PRIMARY KEY (id)
);
CREATE TABLE jobs (
	id BIGINT NOT NULL, 
	queue VARCHAR(255) NOT NULL, 
	payload TEXT NOT NULL, 
	attempts SMALLINT NOT NULL, 
	reserved_at INTEGER, 
	available_at INTEGER NOT NULL, 
	created_at INTEGER NOT NULL, 
	CONSTRAINT jobs_pkey PRIMARY KEY (id)
);
CREATE TABLE log_viewer_shares (
	id CHAR(26) NOT NULL, 
	server_id CHAR(26) NOT NULL, 
	user_id CHAR(26) NOT NULL, 
	token VARCHAR(64) NOT NULL, 
	log_key VARCHAR(255) NOT NULL, 
	content TEXT NOT NULL, 
	expires_at TIMESTAMP NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT log_viewer_shares_pkey PRIMARY KEY (id), 
	CONSTRAINT log_viewer_shares_token_unique UNIQUE (token)
);
CREATE TABLE marketplace_items (
	id CHAR(26) NOT NULL, 
	slug VARCHAR(255) NOT NULL, 
	name VARCHAR(255) NOT NULL, 
	summary TEXT, 
	category VARCHAR(32) NOT NULL, 
	recipe_type VARCHAR(32) NOT NULL, 
	payload JSON NOT NULL, 
	sort_order SMALLINT DEFAULT '0' NOT NULL, 
	is_active BOOLEAN DEFAULT 1 NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT marketplace_items_pkey PRIMARY KEY (id), 
	CONSTRAINT marketplace_items_slug_unique UNIQUE (slug)
);
CREATE TABLE migrations (
	id INTEGER NOT NULL, 
	migration VARCHAR(255) NOT NULL, 
	batch INTEGER NOT NULL, 
	CONSTRAINT migrations_pkey PRIMARY KEY (id)
);
CREATE TABLE notification_channels (
	id CHAR(26) NOT NULL, 
	owner_type VARCHAR(255) NOT NULL, 
	owner_id CHAR(26) NOT NULL, 
	type VARCHAR(32) NOT NULL, 
	label VARCHAR(160) NOT NULL, 
	config TEXT NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT notification_channels_pkey PRIMARY KEY (id)
);
CREATE TABLE notification_events (
	id CHAR(26) NOT NULL, 
	event_key VARCHAR(255) NOT NULL, 
	subject_type VARCHAR(255), 
	subject_id CHAR(26), 
	resource_type VARCHAR(255), 
	resource_id CHAR(26), 
	organization_id CHAR(26), 
	team_id CHAR(26), 
	actor_id CHAR(26), 
	title VARCHAR(255) NOT NULL, 
	body TEXT, 
	url TEXT, 
	severity VARCHAR(32) DEFAULT 'info' NOT NULL, 
	category VARCHAR(64), 
	supports_in_app BOOLEAN DEFAULT 1 NOT NULL, 
	supports_email BOOLEAN DEFAULT 0 NOT NULL, 
	supports_webhook BOOLEAN DEFAULT 1 NOT NULL, 
	metadata JSON, 
	occurred_at TIMESTAMP, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT notification_events_pkey PRIMARY KEY (id)
);
CREATE TABLE notification_inbox_items (
	id CHAR(26) NOT NULL, 
	notification_event_id CHAR(26) NOT NULL, 
	user_id CHAR(26) NOT NULL, 
	resource_type VARCHAR(255), 
	resource_id CHAR(26), 
	title VARCHAR(255) NOT NULL, 
	body TEXT, 
	url TEXT, 
	metadata JSON, 
	read_at TIMESTAMP, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT notification_inbox_items_pkey PRIMARY KEY (id)
);
CREATE TABLE notification_subscriptions (
	id CHAR(26) NOT NULL, 
	notification_channel_id CHAR(26) NOT NULL, 
	subscribable_type VARCHAR(255) NOT NULL, 
	subscribable_id CHAR(26) NOT NULL, 
	event_key VARCHAR(80) NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT notification_subscriptions_pkey PRIMARY KEY (id), 
	CONSTRAINT notification_subscriptions_unique UNIQUE (notification_channel_id, subscribable_type, subscribable_id, event_key)
);
CREATE TABLE notification_webhook_destinations (
	id CHAR(26) NOT NULL, 
	organization_id CHAR(26) NOT NULL, 
	site_id CHAR(26), 
	name VARCHAR(120) NOT NULL, 
	driver VARCHAR(24) NOT NULL, 
	webhook_url TEXT NOT NULL, 
	events JSON, 
	enabled BOOLEAN DEFAULT 1 NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT integration_outbound_webhooks_pkey PRIMARY KEY (id)
);
CREATE TABLE notifications (
	id UUID NOT NULL, 
	type VARCHAR(255) NOT NULL, 
	notifiable_type VARCHAR(255) NOT NULL, 
	notifiable_id VARCHAR(255) NOT NULL, 
	data TEXT NOT NULL, 
	read_at TIMESTAMP, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT notifications_pkey PRIMARY KEY (id)
);
CREATE TABLE organization_cron_job_templates (
	id CHAR(26) NOT NULL, 
	organization_id CHAR(26) NOT NULL, 
	name VARCHAR(255) NOT NULL, 
	cron_expression VARCHAR(64) NOT NULL, 
	command TEXT NOT NULL, 
	user VARCHAR(255) DEFAULT 'root' NOT NULL, 
	description TEXT, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT organization_cron_job_templates_pkey PRIMARY KEY (id), 
	CONSTRAINT organization_cron_job_templates_organization_id_name_unique UNIQUE (organization_id, name)
);
CREATE TABLE organization_invitations (
	id CHAR(26) NOT NULL, 
	organization_id CHAR(26) NOT NULL, 
	email VARCHAR(255) NOT NULL, 
	role VARCHAR(255) DEFAULT 'member' NOT NULL, 
	token VARCHAR(64) NOT NULL, 
	invited_by CHAR(26) NOT NULL, 
	expires_at TIMESTAMP NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT organization_invitations_pkey PRIMARY KEY (id), 
	CONSTRAINT organization_invitations_organization_id_email_unique UNIQUE (organization_id, email), 
	CONSTRAINT organization_invitations_token_unique UNIQUE (token)
);
CREATE TABLE organization_ssh_keys (
	id CHAR(26) NOT NULL, 
	organization_id CHAR(26) NOT NULL, 
	name VARCHAR(120) NOT NULL, 
	public_key TEXT NOT NULL, 
	provision_on_new_servers BOOLEAN DEFAULT 0 NOT NULL, 
	created_by CHAR(26), 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT organization_ssh_keys_pkey PRIMARY KEY (id)
);
CREATE TABLE organization_supervisor_program_templates (
	id CHAR(26) NOT NULL, 
	organization_id CHAR(26) NOT NULL, 
	name VARCHAR(160) NOT NULL, 
	slug VARCHAR(64) NOT NULL, 
	program_type VARCHAR(32) NOT NULL, 
	command TEXT NOT NULL, 
	directory VARCHAR(512) NOT NULL, 
	user VARCHAR(64) DEFAULT 'www-data' NOT NULL, 
	numprocs SMALLINT DEFAULT '1' NOT NULL, 
	env_vars JSON, 
	stdout_logfile VARCHAR(512), 
	stderr_logfile VARCHAR(512), 
	priority SMALLINT, 
	startsecs SMALLINT, 
	stopwaitsecs SMALLINT, 
	autorestart VARCHAR(32), 
	redirect_stderr BOOLEAN DEFAULT 1 NOT NULL, 
	description TEXT, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT organization_supervisor_program_templates_pkey PRIMARY KEY (id), 
	CONSTRAINT organization_supervisor_program_templates_organization_id_slug_ UNIQUE (organization_id, slug)
);
CREATE TABLE organization_user (
	organization_id CHAR(26) NOT NULL, 
	user_id CHAR(26) NOT NULL, 
	role VARCHAR(255) DEFAULT 'member' NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT organization_user_pkey PRIMARY KEY (organization_id, user_id)
);
CREATE TABLE organizations (
	id CHAR(26) NOT NULL, 
	name VARCHAR(255) NOT NULL, 
	slug VARCHAR(255) NOT NULL, 
	email VARCHAR(255), 
	stripe_id VARCHAR(255), 
	pm_type VARCHAR(255), 
	pm_last_four VARCHAR(4), 
	trial_ends_at TIMESTAMP, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	deploy_email_notifications_enabled BOOLEAN DEFAULT 1 NOT NULL, 
	server_site_preferences JSON, 
	default_site_script_id CHAR(26), 
	cron_maintenance_until TIMESTAMP, 
	cron_maintenance_note VARCHAR(500), 
	insights_preferences JSON, 
	firewall_settings JSON, 
	services_preferences JSON, 
	database_workspace_settings JSON, 
	CONSTRAINT organizations_pkey PRIMARY KEY (id), 
	CONSTRAINT organizations_slug_unique UNIQUE (slug)
);
CREATE TABLE password_reset_tokens (
	email VARCHAR(255) NOT NULL, 
	token VARCHAR(255) NOT NULL, 
	created_at TIMESTAMP, 
	CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email)
);
CREATE TABLE projects (
	id CHAR(26) NOT NULL, 
	organization_id CHAR(26), 
	user_id CHAR(26) NOT NULL, 
	name VARCHAR(255) NOT NULL, 
	slug VARCHAR(255) NOT NULL, 
	kind VARCHAR(32) DEFAULT 'byo_site' NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT projects_pkey PRIMARY KEY (id), 
	CONSTRAINT projects_slug_unique UNIQUE (slug)
);
CREATE TABLE provider_credentials (
	id CHAR(26) NOT NULL, 
	user_id CHAR(26) NOT NULL, 
	provider VARCHAR(255) NOT NULL, 
	name VARCHAR(255), 
	credentials TEXT NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	organization_id CHAR(26), 
	CONSTRAINT provider_credentials_pkey PRIMARY KEY (id)
);
CREATE TABLE pulse_aggregates (
	id BIGINT NOT NULL, 
	bucket INTEGER NOT NULL, 
	period INTEGER NOT NULL, 
	type VARCHAR(255) NOT NULL, 
	"key" TEXT NOT NULL, 
	key_hash UUID NOT NULL, 
	aggregate VARCHAR(255) NOT NULL, 
	value NUMERIC(20, 2) NOT NULL, 
	count INTEGER, 
	CONSTRAINT pulse_aggregates_pkey PRIMARY KEY (id), 
	CONSTRAINT pulse_aggregates_bucket_period_type_aggregate_key_hash_unique UNIQUE (bucket, period, type, aggregate, key_hash)
);
CREATE TABLE pulse_entries (
	id BIGINT NOT NULL, 
	timestamp INTEGER NOT NULL, 
	type VARCHAR(255) NOT NULL, 
	"key" TEXT NOT NULL, 
	key_hash UUID NOT NULL, 
	value BIGINT, 
	CONSTRAINT pulse_entries_pkey PRIMARY KEY (id)
);
CREATE TABLE pulse_values (
	id BIGINT NOT NULL, 
	timestamp INTEGER NOT NULL, 
	type VARCHAR(255) NOT NULL, 
	"key" TEXT NOT NULL, 
	key_hash UUID NOT NULL, 
	value TEXT NOT NULL, 
	CONSTRAINT pulse_values_pkey PRIMARY KEY (id), 
	CONSTRAINT pulse_values_type_key_hash_unique UNIQUE (type, key_hash)
);
CREATE TABLE referral_rewards (
	id CHAR(26) NOT NULL, 
	referrer_user_id CHAR(26) NOT NULL, 
	referred_user_id CHAR(26) NOT NULL, 
	referrer_organization_id CHAR(26), 
	bonus_credit_cents INTEGER DEFAULT 0 NOT NULL, 
	stripe_balance_transaction_id VARCHAR(255), 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT referral_rewards_pkey PRIMARY KEY (id), 
	CONSTRAINT referral_rewards_referred_user_id_unique UNIQUE (referred_user_id)
);
CREATE TABLE scripts (
	id CHAR(26) NOT NULL, 
	organization_id CHAR(26) NOT NULL, 
	user_id CHAR(26) NOT NULL, 
	name VARCHAR(255) NOT NULL, 
	content TEXT NOT NULL, 
	run_as_user VARCHAR(64), 
	source VARCHAR(32) DEFAULT 'user_created' NOT NULL, 
	marketplace_key VARCHAR(64), 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT scripts_pkey PRIMARY KEY (id)
);
CREATE TABLE server_authorized_keys (
	id CHAR(26) NOT NULL, 
	server_id CHAR(26) NOT NULL, 
	name VARCHAR(120) NOT NULL, 
	public_key TEXT NOT NULL, 
	synced_at TIMESTAMP, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	managed_key_type VARCHAR(255), 
	managed_key_id CHAR(26), 
	target_linux_user VARCHAR(64) DEFAULT '' NOT NULL, 
	review_after DATE, 
	CONSTRAINT server_authorized_keys_pkey PRIMARY KEY (id), 
	CONSTRAINT srv_auth_keys_managed_target_unique UNIQUE (server_id, managed_key_type, managed_key_id, target_linux_user)
);
CREATE TABLE server_cron_job_runs (
	id CHAR(26) NOT NULL, 
	server_cron_job_id CHAR(26) NOT NULL, 
	run_ulid VARCHAR(32) NOT NULL, 
	"trigger" VARCHAR(16) DEFAULT 'manual' NOT NULL, 
	status VARCHAR(16) DEFAULT 'running' NOT NULL, 
	exit_code SMALLINT, 
	duration_ms INTEGER, 
	output TEXT, 
	error_message TEXT, 
	started_at TIMESTAMP NOT NULL, 
	finished_at TIMESTAMP, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT server_cron_job_runs_pkey PRIMARY KEY (id)
);
CREATE TABLE server_cron_jobs (
	id CHAR(26) NOT NULL, 
	server_id CHAR(26) NOT NULL, 
	cron_expression VARCHAR(64) NOT NULL, 
	command TEXT NOT NULL, 
	user VARCHAR(255) DEFAULT 'root' NOT NULL, 
	is_synced BOOLEAN DEFAULT 0 NOT NULL, 
	last_sync_error TEXT, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	enabled BOOLEAN DEFAULT 1 NOT NULL, 
	description TEXT, 
	site_id CHAR(26), 
	last_run_at TIMESTAMP, 
	last_run_output TEXT, 
	schedule_timezone VARCHAR(64), 
	overlap_policy VARCHAR(24) DEFAULT 'allow' NOT NULL, 
	alert_on_failure BOOLEAN DEFAULT 0 NOT NULL, 
	alert_on_pattern_match BOOLEAN DEFAULT 0 NOT NULL, 
	alert_pattern VARCHAR(512), 
	env_prefix TEXT, 
	depends_on_job_id CHAR(26), 
	maintenance_tag VARCHAR(64), 
	applied_template_id CHAR(26), 
	CONSTRAINT server_cron_jobs_pkey PRIMARY KEY (id)
);
CREATE TABLE server_database_admin_credentials (
	id CHAR(26) NOT NULL, 
	server_id CHAR(26) NOT NULL, 
	mysql_root_username VARCHAR(255) DEFAULT 'root' NOT NULL, 
	mysql_root_password TEXT, 
	postgres_superuser VARCHAR(255) DEFAULT 'postgres' NOT NULL, 
	postgres_password TEXT, 
	postgres_use_sudo BOOLEAN DEFAULT 1 NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT server_database_admin_credentials_pkey PRIMARY KEY (id), 
	CONSTRAINT server_database_admin_credentials_server_id_unique UNIQUE (server_id)
);
CREATE TABLE server_database_audit_events (
	id CHAR(26) NOT NULL, 
	server_id CHAR(26) NOT NULL, 
	user_id CHAR(26), 
	event VARCHAR(255) NOT NULL, 
	meta JSON, 
	ip_address VARCHAR(45), 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT server_database_audit_events_pkey PRIMARY KEY (id)
);
CREATE TABLE server_database_backups (
	id CHAR(26) NOT NULL, 
	server_database_id CHAR(26) NOT NULL, 
	user_id CHAR(26), 
	status VARCHAR(255) DEFAULT 'pending' NOT NULL, 
	disk_path VARCHAR(255), 
	bytes BIGINT, 
	error_message TEXT, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT server_database_backups_pkey PRIMARY KEY (id)
);
CREATE TABLE server_database_credential_shares (
	id CHAR(26) NOT NULL, 
	server_database_id CHAR(26) NOT NULL, 
	user_id CHAR(26) NOT NULL, 
	token VARCHAR(64) NOT NULL, 
	expires_at TIMESTAMP NOT NULL, 
	views_remaining SMALLINT DEFAULT '1' NOT NULL, 
	max_views SMALLINT DEFAULT '1' NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT server_database_credential_shares_pkey PRIMARY KEY (id), 
	CONSTRAINT server_database_credential_shares_token_unique UNIQUE (token)
);
CREATE TABLE server_database_extra_users (
	id CHAR(26) NOT NULL, 
	server_database_id CHAR(26) NOT NULL, 
	username VARCHAR(255) NOT NULL, 
	password TEXT NOT NULL, 
	host VARCHAR(255) DEFAULT 'localhost' NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT server_database_extra_users_pkey PRIMARY KEY (id), 
	CONSTRAINT server_database_extra_users_server_database_id_username_host_un UNIQUE (server_database_id, username, host)
);
CREATE TABLE server_databases (
	id CHAR(26) NOT NULL, 
	server_id CHAR(26) NOT NULL, 
	name VARCHAR(255) NOT NULL, 
	engine VARCHAR(255) DEFAULT 'mysql' NOT NULL, 
	username VARCHAR(255) NOT NULL, 
	password TEXT NOT NULL, 
	host VARCHAR(255) DEFAULT '127.0.0.1' NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	description TEXT, 
	mysql_charset VARCHAR(255), 
	mysql_collation VARCHAR(255), 
	CONSTRAINT server_databases_pkey PRIMARY KEY (id), 
	CONSTRAINT server_databases_server_id_name_unique UNIQUE (server_id, name)
);
CREATE TABLE server_firewall_apply_logs (
	id CHAR(26) NOT NULL, 
	server_id CHAR(26) NOT NULL, 
	user_id CHAR(26), 
	api_token_id CHAR(26), 
	kind VARCHAR(32) DEFAULT 'apply' NOT NULL, 
	success BOOLEAN DEFAULT 1 NOT NULL, 
	rules_hash VARCHAR(64), 
	rule_count SMALLINT DEFAULT '0' NOT NULL, 
	message TEXT, 
	meta JSON, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT server_firewall_apply_logs_pkey PRIMARY KEY (id)
);
CREATE TABLE server_firewall_audit_events (
	id CHAR(26) NOT NULL, 
	server_id CHAR(26) NOT NULL, 
	user_id CHAR(26), 
	api_token_id CHAR(26), 
	event VARCHAR(64) NOT NULL, 
	meta JSON, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT server_firewall_audit_events_pkey PRIMARY KEY (id)
);
CREATE TABLE server_firewall_rules (
	id CHAR(26) NOT NULL, 
	server_id CHAR(26) NOT NULL, 
	port SMALLINT, 
	protocol VARCHAR(16) DEFAULT 'tcp' NOT NULL, 
	action VARCHAR(8) DEFAULT 'allow' NOT NULL, 
	sort_order INTEGER DEFAULT 0 NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	name VARCHAR(160), 
	source VARCHAR(128) DEFAULT 'any' NOT NULL, 
	enabled BOOLEAN DEFAULT 1 NOT NULL, 
	profile VARCHAR(32), 
	tags JSON, 
	runbook_url VARCHAR(2048), 
	site_id CHAR(26), 
	CONSTRAINT server_firewall_rules_pkey PRIMARY KEY (id)
);
CREATE TABLE server_firewall_snapshots (
	id CHAR(26) NOT NULL, 
	server_id CHAR(26) NOT NULL, 
	user_id CHAR(26), 
	label VARCHAR(200), 
	rules JSON NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT server_firewall_snapshots_pkey PRIMARY KEY (id)
);
CREATE TABLE server_log_pins (
	id CHAR(26) NOT NULL, 
	server_id CHAR(26) NOT NULL, 
	user_id CHAR(26) NOT NULL, 
	log_key VARCHAR(255) NOT NULL, 
	line_fingerprint VARCHAR(64) NOT NULL, 
	note TEXT, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT server_log_pins_pkey PRIMARY KEY (id), 
	CONSTRAINT server_log_pins_unique_line UNIQUE (server_id, user_id, log_key, line_fingerprint)
);
CREATE TABLE server_metric_ingest_events (
	id BIGINT NOT NULL, 
	source_snapshot_id BIGINT, 
	organization_id VARCHAR(26) NOT NULL, 
	server_id VARCHAR(26) NOT NULL, 
	server_name VARCHAR(255), 
	captured_at TIMESTAMP NOT NULL, 
	metrics JSON NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT server_metric_ingest_events_pkey PRIMARY KEY (id)
);
CREATE TABLE server_metric_snapshots (
	id BIGINT NOT NULL, 
	server_id CHAR(26) NOT NULL, 
	captured_at TIMESTAMP NOT NULL, 
	payload JSON NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT server_metric_snapshots_pkey PRIMARY KEY (id)
);
CREATE TABLE server_provision_artifacts (
	id CHAR(26) NOT NULL, 
	server_provision_run_id CHAR(26) NOT NULL, 
	type VARCHAR(255) NOT NULL, 
	"key" VARCHAR(255), 
	label VARCHAR(255) NOT NULL, 
	content TEXT, 
	metadata JSON, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT server_provision_artifacts_pkey PRIMARY KEY (id), 
	CONSTRAINT server_provision_artifacts_unique UNIQUE (server_provision_run_id, type, "key")
);
CREATE TABLE server_provision_runs (
	id CHAR(26) NOT NULL, 
	server_id CHAR(26) NOT NULL, 
	task_id CHAR(26), 
	attempt INTEGER DEFAULT 1 NOT NULL, 
	status VARCHAR(255) DEFAULT 'pending' NOT NULL, 
	rollback_status VARCHAR(255), 
	summary TEXT, 
	started_at TIMESTAMP, 
	completed_at TIMESTAMP, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT server_provision_runs_pkey PRIMARY KEY (id)
);
CREATE TABLE server_recipes (
	id CHAR(26) NOT NULL, 
	server_id CHAR(26) NOT NULL, 
	user_id CHAR(26) NOT NULL, 
	name VARCHAR(160) NOT NULL, 
	script TEXT NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT server_recipes_pkey PRIMARY KEY (id)
);
CREATE TABLE server_ssh_key_audit_events (
	id CHAR(26) NOT NULL, 
	server_id CHAR(26) NOT NULL, 
	user_id CHAR(26), 
	event VARCHAR(72) NOT NULL, 
	ip_address VARCHAR(45), 
	meta JSON, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT server_ssh_key_audit_events_pkey PRIMARY KEY (id)
);
CREATE TABLE server_systemd_notification_digest_lines (
	id BIGINT NOT NULL, 
	notification_channel_id CHAR(26) NOT NULL, 
	server_id CHAR(26) NOT NULL, 
	organization_id CHAR(26) NOT NULL, 
	digest_bucket VARCHAR(32) NOT NULL, 
	unit VARCHAR(255) NOT NULL, 
	event_kind VARCHAR(32) NOT NULL, 
	line TEXT NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT server_systemd_notification_digest_lines_pkey PRIMARY KEY (id)
);
CREATE TABLE server_systemd_service_audit_events (
	id BIGINT NOT NULL, 
	server_id CHAR(26) NOT NULL, 
	occurred_at TIMESTAMP NOT NULL, 
	kind VARCHAR(32) NOT NULL, 
	unit VARCHAR(255) NOT NULL, 
	label VARCHAR(255) DEFAULT '' NOT NULL, 
	detail TEXT, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT server_systemd_service_audit_events_pkey PRIMARY KEY (id)
);
CREATE TABLE server_systemd_service_states (
	id BIGINT NOT NULL, 
	server_id CHAR(26) NOT NULL, 
	unit VARCHAR(255) NOT NULL, 
	label VARCHAR(255) NOT NULL, 
	active_state VARCHAR(64) DEFAULT '' NOT NULL, 
	sub_state VARCHAR(64) DEFAULT '' NOT NULL, 
	active_enter_ts TEXT, 
	version VARCHAR(128) DEFAULT '' NOT NULL, 
	is_custom BOOLEAN DEFAULT 0 NOT NULL, 
	can_manage BOOLEAN DEFAULT 0 NOT NULL, 
	captured_at TIMESTAMP NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	unit_file_state VARCHAR(64), 
	main_pid VARCHAR(32), 
	CONSTRAINT server_systemd_service_states_pkey PRIMARY KEY (id), 
	CONSTRAINT server_systemd_service_states_server_id_unit_unique UNIQUE (server_id, unit)
);
CREATE TABLE servers (
	id CHAR(26) NOT NULL, 
	user_id CHAR(26) NOT NULL, 
	provider_credential_id CHAR(26), 
	name VARCHAR(255) NOT NULL, 
	provider VARCHAR(255) DEFAULT 'digitalocean' NOT NULL, 
	provider_id VARCHAR(255), 
	ip_address VARCHAR(255), 
	ssh_port SMALLINT DEFAULT '22' NOT NULL, 
	ssh_user VARCHAR(255) DEFAULT 'root' NOT NULL, 
	ssh_private_key TEXT, 
	status VARCHAR(255) DEFAULT 'pending' NOT NULL, 
	region VARCHAR(255), 
	size VARCHAR(255), 
	meta JSON, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	organization_id CHAR(26), 
	team_id CHAR(26), 
	last_health_check_at TIMESTAMP, 
	health_status VARCHAR(255), 
	deploy_command TEXT, 
	setup_script_key VARCHAR(255), 
	setup_status VARCHAR(255), 
	scheduled_deletion_at TIMESTAMP, 
	workspace_id CHAR(26), 
	supervisor_package_status VARCHAR(16), 
	ssh_operational_private_key TEXT, 
	ssh_recovery_private_key TEXT, 
	CONSTRAINT servers_pkey PRIMARY KEY (id)
);
CREATE TABLE sessions (
	id VARCHAR(255) NOT NULL, 
	user_id CHAR(26), 
	ip_address VARCHAR(45), 
	user_agent TEXT, 
	payload TEXT NOT NULL, 
	last_activity INTEGER NOT NULL, 
	CONSTRAINT sessions_pkey PRIMARY KEY (id)
);
CREATE TABLE site_deploy_hooks (
	id CHAR(26) NOT NULL, 
	site_id CHAR(26) NOT NULL, 
	sort_order INTEGER DEFAULT 0 NOT NULL, 
	phase VARCHAR(32) NOT NULL, 
	script TEXT NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	timeout_seconds SMALLINT DEFAULT '900' NOT NULL, 
	CONSTRAINT site_deploy_hooks_pkey PRIMARY KEY (id)
);
CREATE TABLE site_deploy_steps (
	id CHAR(26) NOT NULL, 
	site_id CHAR(26) NOT NULL, 
	sort_order INTEGER DEFAULT 0 NOT NULL, 
	step_type VARCHAR(64) NOT NULL, 
	custom_command TEXT, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	timeout_seconds SMALLINT DEFAULT '900' NOT NULL, 
	CONSTRAINT site_deploy_steps_pkey PRIMARY KEY (id)
);
CREATE TABLE site_deployments (
	id CHAR(26) NOT NULL, 
	site_id CHAR(26) NOT NULL, 
	"trigger" VARCHAR(255) NOT NULL, 
	status VARCHAR(255) NOT NULL, 
	git_sha VARCHAR(64), 
	exit_code SMALLINT, 
	log_output TEXT, 
	started_at TIMESTAMP, 
	finished_at TIMESTAMP, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	idempotency_key VARCHAR(128), 
	project_id CHAR(26) NOT NULL, 
	CONSTRAINT site_deployments_pkey PRIMARY KEY (id)
);
CREATE TABLE site_domains (
	id CHAR(26) NOT NULL, 
	site_id CHAR(26) NOT NULL, 
	hostname VARCHAR(255) NOT NULL, 
	is_primary BOOLEAN DEFAULT 0 NOT NULL, 
	www_redirect BOOLEAN DEFAULT 0 NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT site_domains_pkey PRIMARY KEY (id), 
	CONSTRAINT site_domains_hostname_unique UNIQUE (hostname)
);
CREATE TABLE site_domain_aliases (
	id CHAR(26) NOT NULL,
	site_id CHAR(26) NOT NULL,
	hostname VARCHAR(255) NOT NULL,
	label VARCHAR(255),
	sort_order INTEGER DEFAULT 0 NOT NULL,
	meta JSON,
	created_at TIMESTAMP,
	updated_at TIMESTAMP,
	CONSTRAINT site_domain_aliases_pkey PRIMARY KEY (id),
	CONSTRAINT site_domain_aliases_hostname_unique UNIQUE (hostname)
);
CREATE INDEX site_domain_aliases_site_id_sort_order_index ON site_domain_aliases (site_id, sort_order);
CREATE TABLE site_preview_domains (
	id CHAR(26) NOT NULL,
	site_id CHAR(26) NOT NULL,
	hostname VARCHAR(255) NOT NULL,
	label VARCHAR(255),
	zone VARCHAR(255),
	record_name VARCHAR(255),
	provider_type VARCHAR(255),
	provider_record_id VARCHAR(255),
	record_type VARCHAR(255),
	record_data VARCHAR(255),
	dns_status VARCHAR(255) DEFAULT 'pending' NOT NULL,
	ssl_status VARCHAR(255) DEFAULT 'none' NOT NULL,
	is_primary BOOLEAN DEFAULT 0 NOT NULL,
	auto_ssl BOOLEAN DEFAULT 1 NOT NULL,
	https_redirect BOOLEAN DEFAULT 1 NOT NULL,
	managed_by_dply BOOLEAN DEFAULT 1 NOT NULL,
	last_dns_checked_at TIMESTAMP,
	last_ssl_checked_at TIMESTAMP,
	meta JSON,
	created_at TIMESTAMP,
	updated_at TIMESTAMP,
	CONSTRAINT site_preview_domains_pkey PRIMARY KEY (id),
	CONSTRAINT site_preview_domains_hostname_unique UNIQUE (hostname)
);
CREATE INDEX site_preview_domains_site_id_is_primary_index ON site_preview_domains (site_id, is_primary);
CREATE TABLE site_tenant_domains (
	id CHAR(26) NOT NULL,
	site_id CHAR(26) NOT NULL,
	hostname VARCHAR(255) NOT NULL,
	tenant_key VARCHAR(255),
	label VARCHAR(255),
	notes TEXT,
	sort_order INTEGER DEFAULT 0 NOT NULL,
	meta JSON,
	created_at TIMESTAMP,
	updated_at TIMESTAMP,
	CONSTRAINT site_tenant_domains_pkey PRIMARY KEY (id),
	CONSTRAINT site_tenant_domains_hostname_unique UNIQUE (hostname)
);
CREATE INDEX site_tenant_domains_site_id_sort_order_index ON site_tenant_domains (site_id, sort_order);
CREATE TABLE site_environment_variables (
	id CHAR(26) NOT NULL, 
	site_id CHAR(26) NOT NULL, 
	env_key VARCHAR(128) NOT NULL, 
	env_value TEXT, 
	environment VARCHAR(32) DEFAULT 'production' NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT site_environment_variables_pkey PRIMARY KEY (id), 
	CONSTRAINT site_environment_variables_site_id_env_key_environment_unique UNIQUE (site_id, env_key, environment)
);
CREATE TABLE site_redirects (
	id CHAR(26) NOT NULL, 
	site_id CHAR(26) NOT NULL, 
	kind VARCHAR(32) DEFAULT 'http' NOT NULL, 
	from_path VARCHAR(512) NOT NULL, 
	to_url VARCHAR(1024) NOT NULL, 
	status_code SMALLINT DEFAULT '301' NOT NULL, 
	response_headers TEXT, 
	sort_order INTEGER DEFAULT 0 NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT site_redirects_pkey PRIMARY KEY (id)
);
CREATE TABLE site_releases (
	id CHAR(26) NOT NULL, 
	site_id CHAR(26) NOT NULL, 
	folder VARCHAR(32) NOT NULL, 
	git_sha VARCHAR(64), 
	is_active BOOLEAN DEFAULT 0 NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT site_releases_pkey PRIMARY KEY (id), 
	CONSTRAINT site_releases_site_id_folder_unique UNIQUE (site_id, folder)
);
CREATE TABLE site_certificates (
	id CHAR(26) NOT NULL,
	site_id CHAR(26) NOT NULL,
	preview_domain_id CHAR(26),
	provider_credential_id CHAR(26),
	scope_type VARCHAR(255) NOT NULL,
	provider_type VARCHAR(255) NOT NULL,
	challenge_type VARCHAR(255) NOT NULL,
	dns_provider VARCHAR(255),
	credential_reference VARCHAR(255),
	domains_json JSON NOT NULL,
	status VARCHAR(255) DEFAULT 'pending' NOT NULL,
	force_skip_dns_checks BOOLEAN DEFAULT 0 NOT NULL,
	enable_http3 BOOLEAN DEFAULT 0 NOT NULL,
	certificate_path VARCHAR(255),
	private_key_path VARCHAR(255),
	chain_path VARCHAR(255),
	certificate_pem TEXT,
	private_key_pem TEXT,
	chain_pem TEXT,
	csr_pem TEXT,
	last_output TEXT,
	requested_settings JSON,
	applied_settings JSON,
	meta JSON,
	expires_at TIMESTAMP,
	last_requested_at TIMESTAMP,
	last_installed_at TIMESTAMP,
	created_at TIMESTAMP,
	updated_at TIMESTAMP,
	CONSTRAINT site_certificates_pkey PRIMARY KEY (id)
);
CREATE INDEX site_certificates_site_id_status_index ON site_certificates (site_id, status);
CREATE INDEX site_certificates_scope_type_provider_type_index ON site_certificates (scope_type, provider_type);
CREATE TABLE sites (
	id CHAR(26) NOT NULL, 
	server_id CHAR(26) NOT NULL, 
	user_id CHAR(26) NOT NULL, 
	organization_id CHAR(26), 
	name VARCHAR(255) NOT NULL, 
	slug VARCHAR(255) NOT NULL, 
	type VARCHAR(255) DEFAULT 'php' NOT NULL, 
	document_root VARCHAR(255) NOT NULL, 
	repository_path VARCHAR(255), 
	php_version VARCHAR(255), 
	app_port SMALLINT, 
	status VARCHAR(255) DEFAULT 'pending' NOT NULL, 
	ssl_status VARCHAR(255) DEFAULT 'none' NOT NULL, 
	nginx_installed_at TIMESTAMP, 
	ssl_installed_at TIMESTAMP, 
	last_deploy_at TIMESTAMP, 
	git_repository_url VARCHAR(255), 
	git_branch VARCHAR(255) DEFAULT 'main' NOT NULL, 
	git_deploy_key_private TEXT, 
	git_deploy_key_public TEXT, 
	webhook_secret TEXT, 
	post_deploy_command TEXT, 
	env_file_content TEXT, 
	meta JSON, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	deploy_strategy VARCHAR(255) DEFAULT 'simple' NOT NULL, 
	releases_to_keep SMALLINT DEFAULT '5' NOT NULL, 
	nginx_extra_raw TEXT, 
	octane_port SMALLINT, 
	laravel_scheduler BOOLEAN DEFAULT 0 NOT NULL, 
	deployment_environment VARCHAR(255) DEFAULT 'production' NOT NULL, 
	php_fpm_user VARCHAR(255), 
	webhook_allowed_ips JSON, 
	project_id CHAR(26) NOT NULL, 
	workspace_id CHAR(26), 
	deploy_script_id CHAR(26), 
	restart_supervisor_programs_after_deploy BOOLEAN DEFAULT 0 NOT NULL, 
	CONSTRAINT sites_pkey PRIMARY KEY (id), 
	CONSTRAINT sites_project_id_unique UNIQUE (project_id), 
	CONSTRAINT sites_server_id_slug_unique UNIQUE (server_id, slug)
);
CREATE TABLE social_accounts (
	id CHAR(26) NOT NULL, 
	user_id CHAR(26) NOT NULL, 
	provider VARCHAR(255) NOT NULL, 
	provider_id VARCHAR(255) NOT NULL, 
	access_token TEXT, 
	refresh_token TEXT, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	label VARCHAR(255), 
	nickname VARCHAR(255), 
	CONSTRAINT social_accounts_pkey PRIMARY KEY (id), 
	CONSTRAINT social_accounts_provider_provider_id_unique UNIQUE (provider, provider_id)
);
CREATE TABLE status_page_monitors (
	id CHAR(26) NOT NULL, 
	status_page_id CHAR(26) NOT NULL, 
	monitorable_type VARCHAR(255) NOT NULL, 
	monitorable_id CHAR(26) NOT NULL, 
	label VARCHAR(255), 
	sort_order SMALLINT DEFAULT '0' NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT status_page_monitors_pkey PRIMARY KEY (id)
);
CREATE TABLE status_pages (
	id CHAR(26) NOT NULL, 
	organization_id CHAR(26) NOT NULL, 
	user_id CHAR(26) NOT NULL, 
	name VARCHAR(255) NOT NULL, 
	slug VARCHAR(255) NOT NULL, 
	description TEXT, 
	is_public BOOLEAN DEFAULT 1 NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT status_pages_pkey PRIMARY KEY (id), 
	CONSTRAINT status_pages_slug_unique UNIQUE (slug)
);
CREATE TABLE subscription_items (
	id CHAR(26) NOT NULL, 
	subscription_id CHAR(26) NOT NULL, 
	stripe_id VARCHAR(255) NOT NULL, 
	stripe_product VARCHAR(255) NOT NULL, 
	stripe_price VARCHAR(255) NOT NULL, 
	quantity INTEGER, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	meter_id VARCHAR(255), 
	meter_event_name VARCHAR(255), 
	CONSTRAINT subscription_items_pkey PRIMARY KEY (id), 
	CONSTRAINT subscription_items_stripe_id_unique UNIQUE (stripe_id)
);
CREATE TABLE subscriptions (
	id CHAR(26) NOT NULL, 
	organization_id CHAR(26) NOT NULL, 
	type VARCHAR(255) NOT NULL, 
	stripe_id VARCHAR(255) NOT NULL, 
	stripe_status VARCHAR(255) NOT NULL, 
	stripe_price VARCHAR(255), 
	quantity INTEGER, 
	trial_ends_at TIMESTAMP, 
	ends_at TIMESTAMP, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT subscriptions_pkey PRIMARY KEY (id), 
	CONSTRAINT subscriptions_stripe_id_unique UNIQUE (stripe_id)
);
CREATE TABLE supervisor_program_audit_logs (
	id CHAR(26) NOT NULL, 
	organization_id CHAR(26) NOT NULL, 
	server_id CHAR(26) NOT NULL, 
	supervisor_program_id CHAR(26), 
	user_id CHAR(26), 
	action VARCHAR(48) NOT NULL, 
	properties JSON, 
	created_at TIMESTAMP NOT NULL, 
	CONSTRAINT supervisor_program_audit_logs_pkey PRIMARY KEY (id)
);
CREATE TABLE supervisor_programs (
	id CHAR(26) NOT NULL, 
	server_id CHAR(26) NOT NULL, 
	site_id CHAR(26), 
	slug VARCHAR(64) NOT NULL, 
	program_type VARCHAR(32) NOT NULL, 
	command TEXT NOT NULL, 
	directory VARCHAR(512) NOT NULL, 
	user VARCHAR(64) DEFAULT 'www-data' NOT NULL, 
	numprocs SMALLINT DEFAULT '1' NOT NULL, 
	is_active BOOLEAN DEFAULT 1 NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	env_vars JSON, 
	stdout_logfile VARCHAR(512), 
	priority SMALLINT, 
	startsecs SMALLINT, 
	stopwaitsecs SMALLINT, 
	autorestart VARCHAR(32), 
	redirect_stderr BOOLEAN DEFAULT 1 NOT NULL, 
	stderr_logfile VARCHAR(512), 
	CONSTRAINT supervisor_programs_pkey PRIMARY KEY (id), 
	CONSTRAINT supervisor_programs_server_id_slug_unique UNIQUE (server_id, slug)
);
CREATE TABLE task_runner_tasks (
	id CHAR(26) NOT NULL, 
	name VARCHAR(255) NOT NULL, 
	action VARCHAR(255), 
	script TEXT, 
	script_content TEXT, 
	timeout INTEGER, 
	user VARCHAR(255), 
	status VARCHAR(255) NOT NULL, 
	output TEXT, 
	exit_code INTEGER, 
	options JSON, 
	instance TEXT, 
	server_id CHAR(26), 
	created_by CHAR(26), 
	started_at TIMESTAMP, 
	completed_at TIMESTAMP, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT task_runner_tasks_pkey PRIMARY KEY (id)
);
CREATE TABLE team_ssh_keys (
	id CHAR(26) NOT NULL, 
	team_id CHAR(26) NOT NULL, 
	name VARCHAR(120) NOT NULL, 
	public_key TEXT NOT NULL, 
	provision_on_new_servers BOOLEAN DEFAULT 0 NOT NULL, 
	created_by CHAR(26), 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT team_ssh_keys_pkey PRIMARY KEY (id)
);
CREATE TABLE team_user (
	team_id CHAR(26) NOT NULL, 
	user_id CHAR(26) NOT NULL, 
	role VARCHAR(255) DEFAULT 'member' NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT team_user_pkey PRIMARY KEY (team_id, user_id)
);
CREATE TABLE teams (
	id CHAR(26) NOT NULL, 
	organization_id CHAR(26) NOT NULL, 
	name VARCHAR(255) NOT NULL, 
	slug VARCHAR(255), 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	preferences JSON, 
	CONSTRAINT teams_pkey PRIMARY KEY (id)
);
CREATE TABLE user_ssh_keys (
	id CHAR(26) NOT NULL, 
	user_id CHAR(26) NOT NULL, 
	name VARCHAR(120) NOT NULL, 
	public_key TEXT NOT NULL, 
	provision_on_new_servers BOOLEAN DEFAULT 0 NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT user_ssh_keys_pkey PRIMARY KEY (id)
);
CREATE TABLE users (
	id CHAR(26) NOT NULL, 
	name VARCHAR(255) NOT NULL, 
	email VARCHAR(255) NOT NULL, 
	email_verified_at TIMESTAMP, 
	password VARCHAR(255), 
	remember_token VARCHAR(100), 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	stripe_id VARCHAR(255), 
	pm_type VARCHAR(255), 
	pm_last_four VARCHAR(4), 
	trial_ends_at TIMESTAMP, 
	two_factor_secret TEXT, 
	two_factor_recovery_codes TEXT, 
	two_factor_confirmed_at TIMESTAMP, 
	country_code VARCHAR(2), 
	locale VARCHAR(12), 
	timezone VARCHAR(64), 
	invoice_email VARCHAR(255), 
	vat_number VARCHAR(64), 
	billing_currency VARCHAR(3), 
	billing_details TEXT, 
	referral_code VARCHAR(64), 
	referred_by_user_id CHAR(26), 
	referral_converted_at TIMESTAMP, 
	ui_preferences JSON, 
	dply_auth_id BIGINT, 
	CONSTRAINT users_pkey PRIMARY KEY (id), 
	CONSTRAINT users_dply_auth_id_unique UNIQUE (dply_auth_id), 
	CONSTRAINT users_email_unique UNIQUE (email), 
	CONSTRAINT users_referral_code_unique UNIQUE (referral_code)
);
CREATE TABLE webhook_delivery_logs (
	id CHAR(26) NOT NULL, 
	site_id CHAR(26) NOT NULL, 
	request_ip VARCHAR(45), 
	http_status SMALLINT, 
	outcome VARCHAR(32) NOT NULL, 
	detail VARCHAR(512), 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT webhook_delivery_logs_pkey PRIMARY KEY (id)
);
CREATE TABLE webserver_templates (
	id CHAR(26) NOT NULL, 
	organization_id CHAR(26) NOT NULL, 
	user_id CHAR(26), 
	label VARCHAR(255) NOT NULL, 
	content TEXT NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT webserver_templates_pkey PRIMARY KEY (id)
);
CREATE TABLE workspace_deploy_runs (
	id CHAR(26) NOT NULL, 
	workspace_id CHAR(26) NOT NULL, 
	user_id CHAR(26), 
	status VARCHAR(32) DEFAULT 'queued' NOT NULL, 
	site_ids JSON, 
	result_summary JSON, 
	output TEXT, 
	started_at TIMESTAMP, 
	finished_at TIMESTAMP, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT workspace_deploy_runs_pkey PRIMARY KEY (id)
);
CREATE TABLE workspace_environments (
	id CHAR(26) NOT NULL, 
	workspace_id CHAR(26) NOT NULL, 
	name VARCHAR(120) NOT NULL, 
	slug VARCHAR(120) NOT NULL, 
	description TEXT, 
	sort_order INTEGER DEFAULT 0 NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT workspace_environments_pkey PRIMARY KEY (id), 
	CONSTRAINT workspace_environments_workspace_id_slug_unique UNIQUE (workspace_id, slug)
);
CREATE TABLE workspace_label_assignments (
	id CHAR(26) NOT NULL, 
	workspace_id CHAR(26) NOT NULL, 
	workspace_label_id CHAR(26) NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT workspace_label_assignments_pkey PRIMARY KEY (id), 
	CONSTRAINT workspace_label_assignments_workspace_id_workspace_label_id_uni UNIQUE (workspace_id, workspace_label_id)
);
CREATE TABLE workspace_labels (
	id CHAR(26) NOT NULL, 
	organization_id CHAR(26) NOT NULL, 
	name VARCHAR(120) NOT NULL, 
	slug VARCHAR(120) NOT NULL, 
	color VARCHAR(24) DEFAULT 'slate' NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT workspace_labels_pkey PRIMARY KEY (id), 
	CONSTRAINT workspace_labels_organization_id_slug_unique UNIQUE (organization_id, slug)
);
CREATE TABLE workspace_members (
	id CHAR(26) NOT NULL, 
	workspace_id CHAR(26) NOT NULL, 
	user_id CHAR(26) NOT NULL, 
	role VARCHAR(32) NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT workspace_members_pkey PRIMARY KEY (id), 
	CONSTRAINT workspace_members_workspace_id_user_id_unique UNIQUE (workspace_id, user_id)
);
CREATE TABLE workspace_runbooks (
	id CHAR(26) NOT NULL, 
	workspace_id CHAR(26) NOT NULL, 
	title VARCHAR(160) NOT NULL, 
	url VARCHAR(500), 
	body TEXT, 
	sort_order INTEGER DEFAULT 0 NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT workspace_runbooks_pkey PRIMARY KEY (id)
);
CREATE TABLE workspace_variables (
	id CHAR(26) NOT NULL, 
	workspace_id CHAR(26) NOT NULL, 
	env_key VARCHAR(120) NOT NULL, 
	env_value TEXT, 
	is_secret BOOLEAN DEFAULT 1 NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT workspace_variables_pkey PRIMARY KEY (id), 
	CONSTRAINT workspace_variables_workspace_id_env_key_unique UNIQUE (workspace_id, env_key)
);
CREATE TABLE workspace_views (
	id CHAR(26) NOT NULL, 
	organization_id CHAR(26) NOT NULL, 
	user_id CHAR(26) NOT NULL, 
	name VARCHAR(120) NOT NULL, 
	filters JSON NOT NULL, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	CONSTRAINT workspace_views_pkey PRIMARY KEY (id)
);
CREATE TABLE workspaces (
	id CHAR(26) NOT NULL, 
	organization_id CHAR(26) NOT NULL, 
	user_id CHAR(26) NOT NULL, 
	name VARCHAR(255) NOT NULL, 
	slug VARCHAR(255) NOT NULL, 
	description TEXT, 
	created_at TIMESTAMP, 
	updated_at TIMESTAMP, 
	notes TEXT, 
	CONSTRAINT workspaces_pkey PRIMARY KEY (id), 
	CONSTRAINT workspaces_organization_id_slug_unique UNIQUE (organization_id, slug)
);
CREATE INDEX users_stripe_id_index ON users (stripe_id);
CREATE INDEX organizations_stripe_id_index ON organizations (stripe_id);
CREATE INDEX scripts_organization_id_name_index ON scripts (organization_id, name);
CREATE INDEX notifications_notifiable_type_notifiable_id_index ON notifications (notifiable_type, notifiable_id);
CREATE INDEX coming_soon_signups_source_index ON coming_soon_signups (source);
CREATE INDEX cache_expiration_index ON cache (expiration);
CREATE INDEX cache_locks_expiration_index ON cache_locks (expiration);
CREATE INDEX jobs_queue_index ON jobs (queue);
CREATE INDEX notification_channels_owner_type_owner_id_index ON notification_channels (owner_type, owner_id);
CREATE INDEX notification_channels_owner_type_owner_id_type_index ON notification_channels (owner_type, owner_id, type);
CREATE INDEX insight_settings_settingsable_type_settingsable_id_index ON insight_settings (settingsable_type, settingsable_id);
CREATE INDEX server_metric_ingest_events_organization_id_index ON server_metric_ingest_events (organization_id);
CREATE INDEX server_metric_ingest_events_server_id_index ON server_metric_ingest_events (server_id);
CREATE INDEX server_metric_ingest_events_captured_at_index ON server_metric_ingest_events (captured_at);
CREATE INDEX server_metric_ingest_events_organization_id_captured_at_index ON server_metric_ingest_events (organization_id, captured_at);
CREATE INDEX server_metric_ingest_events_source_snapshot_id_index ON server_metric_ingest_events (source_snapshot_id);
CREATE INDEX marketplace_items_recipe_type_index ON marketplace_items (recipe_type);
CREATE INDEX marketplace_items_category_index ON marketplace_items (category);
CREATE INDEX pulse_values_type_index ON pulse_values (type);
CREATE INDEX pulse_values_timestamp_index ON pulse_values (timestamp);
CREATE INDEX pulse_entries_timestamp_index ON pulse_entries (timestamp);
CREATE INDEX pulse_entries_key_hash_index ON pulse_entries (key_hash);
CREATE INDEX pulse_entries_timestamp_type_key_hash_value_index ON pulse_entries (timestamp, type, key_hash, value);
CREATE INDEX pulse_entries_type_index ON pulse_entries (type);
CREATE INDEX pulse_aggregates_period_type_aggregate_bucket_index ON pulse_aggregates (period, type, aggregate, bucket);
CREATE INDEX pulse_aggregates_type_index ON pulse_aggregates (type);
CREATE INDEX pulse_aggregates_period_bucket_index ON pulse_aggregates (period, bucket);
CREATE INDEX provider_credentials_user_id_provider_index ON provider_credentials (user_id, provider);
CREATE INDEX teams_organization_id_index ON teams (organization_id);
CREATE INDEX sessions_last_activity_index ON sessions (last_activity);
CREATE INDEX subscriptions_organization_id_stripe_status_index ON subscriptions (organization_id, stripe_status);
CREATE INDEX audit_logs_created_at_index ON audit_logs (created_at);
CREATE INDEX audit_logs_organization_id_index ON audit_logs (organization_id);
CREATE INDEX api_tokens_token_prefix_index ON api_tokens (token_prefix);
CREATE INDEX user_ssh_keys_user_id_provision_on_new_servers_index ON user_ssh_keys (user_id, provision_on_new_servers);
CREATE INDEX notification_subscriptions_subscribable_type_subscribable_id_in ON notification_subscriptions (subscribable_type, subscribable_id);
CREATE INDEX backup_configurations_user_id_provider_index ON backup_configurations (user_id, provider);
CREATE INDEX webserver_templates_organization_id_label_index ON webserver_templates (organization_id, label);
CREATE INDEX organization_ssh_keys_organization_id_provision_on_new_servers_ ON organization_ssh_keys (organization_id, provision_on_new_servers);
CREATE INDEX servers_user_id_status_index ON servers (user_id, status);
CREATE INDEX notification_events_subject_type_subject_id_index ON notification_events (subject_type, subject_id);
CREATE INDEX notification_events_resource_type_resource_id_index ON notification_events (resource_type, resource_id);
CREATE INDEX notification_events_event_key_resource_type_resource_id_index ON notification_events (event_key, resource_type, resource_id);
CREATE INDEX notification_events_organization_id_created_at_index ON notification_events (organization_id, created_at);
CREATE INDEX subscription_items_subscription_id_stripe_price_index ON subscription_items (subscription_id, stripe_price);
CREATE INDEX incidents_status_page_id_resolved_at_index ON incidents (status_page_id, resolved_at);
CREATE INDEX team_ssh_keys_team_id_provision_on_new_servers_index ON team_ssh_keys (team_id, provision_on_new_servers);
CREATE INDEX status_page_monitors_status_page_id_sort_order_index ON status_page_monitors (status_page_id, sort_order);
CREATE INDEX status_page_monitors_monitorable_type_monitorable_id_index ON status_page_monitors (monitorable_type, monitorable_id);
CREATE INDEX notification_inbox_items_user_id_read_at_created_at_index ON notification_inbox_items (user_id, read_at, created_at);
CREATE INDEX notification_inbox_items_resource_type_resource_id_index ON notification_inbox_items (resource_type, resource_id);
CREATE INDEX server_authorized_keys_managed_key_type_managed_key_id_index ON server_authorized_keys (managed_key_type, managed_key_id);
CREATE INDEX firewall_rule_templates_organization_id_server_id_index ON firewall_rule_templates (organization_id, server_id);
CREATE INDEX server_firewall_snapshots_server_id_created_at_index ON server_firewall_snapshots (server_id, created_at);
CREATE INDEX server_firewall_audit_events_server_id_created_at_index ON server_firewall_audit_events (server_id, created_at);
CREATE INDEX server_metric_snapshots_server_id_captured_at_index ON server_metric_snapshots (server_id, captured_at);
CREATE INDEX log_viewer_shares_server_id_expires_at_index ON log_viewer_shares (server_id, expires_at);
CREATE INDEX server_log_pins_server_id_log_key_index ON server_log_pins (server_id, log_key);
CREATE INDEX server_systemd_service_states_server_id_captured_at_index ON server_systemd_service_states (server_id, captured_at);
CREATE INDEX server_systemd_service_audit_events_server_id_occurred_at_index ON server_systemd_service_audit_events (server_id, occurred_at);
CREATE INDEX incident_updates_incident_id_created_at_index ON incident_updates (incident_id, created_at);
CREATE INDEX insight_health_snapshots_server_id_captured_at_index ON insight_health_snapshots (server_id, captured_at);
CREATE INDEX server_ssh_key_audit_events_server_id_created_at_index ON server_ssh_key_audit_events (server_id, created_at);
CREATE INDEX server_firewall_apply_logs_server_id_created_at_index ON server_firewall_apply_logs (server_id, created_at);
CREATE INDEX server_systemd_notification_digest_lines_digest_bucket_notifica ON server_systemd_notification_digest_lines (digest_bucket, notification_channel_id);
CREATE INDEX server_systemd_notification_digest_lines_created_at_index ON server_systemd_notification_digest_lines (created_at);
CREATE INDEX server_database_audit_events_server_id_created_at_index ON server_database_audit_events (server_id, created_at);
CREATE INDEX site_deployments_project_id_created_at_index ON site_deployments (project_id, created_at);
CREATE INDEX site_deployments_site_id_created_at_index ON site_deployments (site_id, created_at);
CREATE INDEX site_deployments_site_id_idempotency_key_index ON site_deployments (site_id, idempotency_key);
CREATE INDEX site_releases_site_id_is_active_index ON site_releases (site_id, is_active);
CREATE INDEX webhook_delivery_logs_site_id_created_at_index ON webhook_delivery_logs (site_id, created_at);
CREATE INDEX insight_findings_server_id_status_insight_key_index ON insight_findings (server_id, status, insight_key);
CREATE INDEX insight_findings_site_id_status_index ON insight_findings (site_id, status);
CREATE INDEX server_database_credential_shares_expires_at_index ON server_database_credential_shares (expires_at);
CREATE INDEX server_database_backups_server_database_id_created_at_index ON server_database_backups (server_database_id, created_at);
CREATE INDEX server_cron_job_runs_run_ulid_index ON server_cron_job_runs (run_ulid);
CREATE INDEX server_cron_job_runs_server_cron_job_id_started_at_index ON server_cron_job_runs (server_cron_job_id, started_at);
CREATE INDEX supervisor_program_audit_logs_server_id_created_at_index ON supervisor_program_audit_logs (server_id, created_at);
