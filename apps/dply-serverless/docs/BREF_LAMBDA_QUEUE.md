# Bref on Lambda: queues for dply-serverless

The **`serverless.yml`** in this app provisions:

- **`JobsQueue`** — SQS queue named **`dply-serverless-${stage}-jobs`**
- **`worker`** — Lambda using **`Bref\LaravelBridge\Queue\QueueHandler`** (PHP 8.3), triggered by SQS
- **`web`** — HTTP Lambda with **`sqs:SendMessage`** on that queue’s ARN

All functions inherit **`QUEUE_CONNECTION=sqs`**, **`SQS_PREFIX=`** (empty), and **`SQS_QUEUE=`** the queue URL via CloudFormation **`Ref: JobsQueue`**.

## Local development

Keep **`.env`** as **`QUEUE_CONNECTION=database`** (or **`sync`**) so you do not need SQS locally. Only the deployed stack uses the SQS settings from **`serverless.yml`**.

## Deploy

1. Set a real **`APP_KEY`** (e.g. export before deploy, or use Serverless [secrets](https://bref.sh/docs/environment/variables)).
2. Configure **`DB_*`** (and any **`SERVERLESS_*`**) for production — the app still needs a database for models, Pennant, failed jobs, etc.
3. Run **`serverless deploy`** from **`apps/dply-serverless`** (with AWS credentials).

## IAM notes

- **Web** can **send** messages to the jobs queue.
- **Worker** permissions for **receive/delete** are created by the **SQS → Lambda** event source mapping.
- For **customer** Lambda/S3 access, see **[SERVERLESS_AWS_IAM.md](./SERVERLESS_AWS_IAM.md)** (different concern).

## Tuning

- **`VisibilityTimeout`** on the queue is **360** seconds; keep it **≥** the worker Lambda **timeout** (300s in **`serverless.yml`**).
- Adjust **`worker.reservedConcurrency`** if you need a different max parallel job count.

## Optional: no SQS

If you are not ready for SQS, remove the **`worker`** function, **`JobsQueue`** resource, **`iamRoleStatements`** on **`web`**, and the **`QUEUE_*` / `SQS_*`** entries from **`provider.environment`**, then set queue behavior via env/SSM (e.g. **`sync`** is only for debugging — not for production load).
