<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests\Examples;

use App\Modules\TaskRunner\Task;
use App\Modules\TaskRunner\Traits\HasProgressTracking;

class DatabaseBackupTask extends Task
{
    use HasProgressTracking;

    public string $database_name;

    public string $backup_path;

    public string $compression = 'gzip';

    public int $retention_days = 7;

    public bool $notify_on_success = false;

    public bool $notify_on_error = true;

    public ?string $db_host = null;

    public ?string $db_port = null;

    public ?string $db_user = null;

    public ?string $db_password = null;

    public array $dump_options = [];

    public bool $upload_to_cloud = false;

    public ?string $cloud_provider = null;

    public ?string $s3_bucket = null;

    public ?string $s3_prefix = null;

    public ?string $gcs_bucket = null;

    public ?string $gcs_prefix = null;

    public ?string $notification_method = null;

    public ?string $slack_webhook_url = null;

    public ?string $email_address = null;

    public function __construct(array $attributes = [])
    {
        parent::__construct();

        foreach ($attributes as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    public function getView(): string
    {
        return 'tasks.database-backup';
    }

    public function getViewData(): array
    {
        return [
            'database_name' => $this->database_name,
            'backup_path' => $this->backup_path,
            'compression' => $this->compression,
            'retention_days' => $this->retention_days,
            'notify_on_success' => $this->notify_on_success ? 'true' : 'false',
            'notify_on_error' => $this->notify_on_error ? 'true' : 'false',
            'db_host' => $this->db_host,
            'db_port' => $this->db_port,
            'db_user' => $this->db_user,
            'db_password' => $this->db_password,
            'dump_options' => $this->dump_options,
            'upload_to_cloud' => $this->upload_to_cloud,
            'cloud_provider' => $this->cloud_provider,
            's3_bucket' => $this->s3_bucket,
            's3_prefix' => $this->s3_prefix,
            'gcs_bucket' => $this->gcs_bucket,
            'gcs_prefix' => $this->gcs_prefix,
            'notification_method' => $this->notification_method,
            'slack_webhook_url' => $this->slack_webhook_url,
            'email_address' => $this->email_address,
        ];
    }

    public function validate(): void
    {
        parent::validate();

        if (empty($this->database_name)) {
            throw new \InvalidArgumentException('Database name is required');
        }

        if (empty($this->backup_path)) {
            throw new \InvalidArgumentException('Backup path is required');
        }

        if (! in_array($this->compression, ['gzip', 'bzip2'])) {
            throw new \InvalidArgumentException('Compression must be either gzip or bzip2');
        }

        if ($this->upload_to_cloud && empty($this->cloud_provider)) {
            throw new \InvalidArgumentException('Cloud provider is required when upload_to_cloud is enabled');
        }

        if ($this->cloud_provider === 's3' && empty($this->s3_bucket)) {
            throw new \InvalidArgumentException('S3 bucket is required for S3 uploads');
        }

        if ($this->cloud_provider === 'gcs' && empty($this->gcs_bucket)) {
            throw new \InvalidArgumentException('GCS bucket is required for GCS uploads');
        }
    }

    /**
     * Configure S3 upload.
     */
    public function withS3Upload(string $bucket, string $prefix = 'backups'): self
    {
        $this->upload_to_cloud = true;
        $this->cloud_provider = 's3';
        $this->s3_bucket = $bucket;
        $this->s3_prefix = $prefix;

        return $this;
    }

    /**
     * Configure GCS upload.
     */
    public function withGCSUpload(string $bucket, string $prefix = 'backups'): self
    {
        $this->upload_to_cloud = true;
        $this->cloud_provider = 'gcs';
        $this->gcs_bucket = $bucket;
        $this->gcs_prefix = $prefix;

        return $this;
    }

    /**
     * Configure Slack notifications.
     */
    public function withSlackNotifications(string $webhook_url): self
    {
        $this->notification_method = 'slack';
        $this->slack_webhook_url = $webhook_url;

        return $this;
    }

    /**
     * Configure email notifications.
     */
    public function withEmailNotifications(string $email_address): self
    {
        $this->notification_method = 'email';
        $this->email_address = $email_address;

        return $this;
    }

    /**
     * Add dump options.
     */
    public function withDumpOptions(array $options): self
    {
        $this->dump_options = array_merge($this->dump_options, $options);

        return $this;
    }

    /**
     * Set database connection parameters.
     */
    public function withDatabaseConnection(string $host, string $user, string $password, ?string $port = null): self
    {
        $this->db_host = $host;
        $this->db_user = $user;
        $this->db_password = $password;
        $this->db_port = $port;

        return $this;
    }

    /**
     * Create a backup task for a specific database.
     */
    public static function forDatabase(string $database_name, string $backup_path): self
    {
        return new self([
            'database_name' => $database_name,
            'backup_path' => $backup_path,
        ]);
    }

    /**
     * Create a backup task with common configurations.
     */
    public static function create(string $database_name, string $backup_path, array $options = []): self
    {
        $task = new self([
            'database_name' => $database_name,
            'backup_path' => $backup_path,
        ]);

        // Apply options
        foreach ($options as $key => $value) {
            if (property_exists($task, $key)) {
                $task->$key = $value;
            }
        }

        return $task;
    }
}
