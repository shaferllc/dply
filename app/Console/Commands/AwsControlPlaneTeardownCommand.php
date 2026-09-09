<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Best-effort teardown of leftover AWS staging from the abandoned control-plane
 * hosting experiment (NAT/RDS/ALB burn). Uses the local AWS CLI profile.
 */
class AwsControlPlaneTeardownCommand extends Command
{
    protected $signature = 'dply:aws:control-plane:teardown
        {--profile=default : AWS CLI profile}
        {--region=us-west-2 : Region}
        {--vpc-id= : Specific VPC to tear down (optional)}
        {--execute : Actually delete (default dry-run)}';

    protected $description = 'Tear down leftover AWS staging VPC/RDS/NAT from the abandoned AWS host path';

    public function handle(): int
    {
        $profile = (string) $this->option('profile');
        $region = (string) $this->option('region');
        $execute = (bool) $this->option('execute');
        $vpcId = trim((string) $this->option('vpc-id'));

        $identity = $this->awsJson(['sts', 'get-caller-identity', '--profile', $profile]);
        if ($identity === null) {
            $this->error("AWS CLI cannot use profile {$profile}. Configure credentials for the dply.io account and re-run.");
            $this->line('Manual Console teardown: delete RDS → NAT → release EIPs → delete VPC project-vpc in us-west-2.');

            return self::FAILURE;
        }

        $account = (string) ($identity['Account'] ?? '');
        $this->info("AWS account {$account} region {$region} (".($execute ? 'EXECUTE' : 'DRY-RUN').')');

        if ($vpcId === '') {
            $vpcs = $this->awsJson([
                'ec2', 'describe-vpcs', '--profile', $profile, '--region', $region,
                '--filters', 'Name=tag:Name,Values=project-vpc,dply*',
            ]);
            $vpcList = $vpcs['Vpcs'] ?? [];
            if ($vpcList === []) {
                // Fall back to non-default VPCs so we can see what exists.
                $vpcs = $this->awsJson([
                    'ec2', 'describe-vpcs', '--profile', $profile, '--region', $region,
                ]);
                $vpcList = array_values(array_filter(
                    $vpcs['Vpcs'] ?? [],
                    static fn (array $v): bool => ! ($v['IsDefault'] ?? false),
                ));
            }
            if ($vpcList === []) {
                $this->warn('No non-default VPCs found in this account/region — nothing to tear down here.');
                $this->line('If staging lived in account 891377105949 (dply.io Console), switch --profile to that account\'s credentials.');
                $this->newLine();
                $this->info('Console teardown checklist (us-west-2, stop NAT/RDS burn):');
                foreach ([
                    '1. RDS → delete Aurora/Postgres instances + clusters (skip final snapshot if staging-only)',
                    '2. EC2 → NAT gateways → Delete (and release Elastic IPs afterward)',
                    '3. EC2 → Load balancers / target groups used by the staging walkthrough',
                    '4. VPC → select project-vpc → Actions → Delete VPC (cleans subnets/IGW/routes when empty)',
                    '5. Secrets Manager + S3 buckets created for the walkthrough if unused',
                ] as $step) {
                    $this->line($step);
                }

                return self::SUCCESS;
            }
            foreach ($vpcList as $vpc) {
                /** @var list<array{Key?: string, Value?: string}> $tags */
                $tags = is_array($vpc['Tags'] ?? null) ? $vpc['Tags'] : [];
                $name = collect($tags)->firstWhere('Key', 'Name')['Value'] ?? '(no name)';
                $this->line(($vpc['VpcId'] ?? '?')."  {$name}  ".($vpc['CidrBlock'] ?? ''));
            }
            $vpcId = (string) ($vpcList[0]['VpcId'] ?? '');
        }

        if ($vpcId === '') {
            $this->error('No VPC id to tear down.');

            return self::FAILURE;
        }

        $this->warn("Target VPC: {$vpcId}");
        $this->line('Order: DB instances/clusters → NAT gateways → EIPs → VPC (Console "Delete VPC" is safest for dependencies).');

        if (! $execute) {
            $this->warn('Dry-run only. Re-run with --execute after confirming the account is the staging one.');

            return self::SUCCESS;
        }

        // Prefer Console-equivalent: delete RDS first, then ask delete-vpc.
        $dbs = $this->awsJson([
            'rds', 'describe-db-instances', '--profile', $profile, '--region', $region,
        ]);
        foreach ($dbs['DBInstances'] ?? [] as $db) {
            $id = (string) ($db['DBInstanceIdentifier'] ?? '');
            if ($id === '') {
                continue;
            }
            $this->line("deleting RDS instance {$id}…");
            $this->awsOk([
                'rds', 'delete-db-instance',
                '--profile', $profile, '--region', $region,
                '--db-instance-identifier', $id,
                '--skip-final-snapshot',
                '--delete-automated-backups',
            ]);
        }

        $clusters = $this->awsJson([
            'rds', 'describe-db-clusters', '--profile', $profile, '--region', $region,
        ]);
        foreach ($clusters['DBClusters'] ?? [] as $cluster) {
            $id = (string) ($cluster['DBClusterIdentifier'] ?? '');
            if ($id === '') {
                continue;
            }
            $this->line("deleting RDS cluster {$id}…");
            $this->awsOk([
                'rds', 'delete-db-cluster',
                '--profile', $profile, '--region', $region,
                '--db-cluster-identifier', $id,
                '--skip-final-snapshot',
            ]);
        }

        $this->info('RDS delete requested. Finish remaining NAT/ENI/VPC cleanup in Console (Delete VPC) once RDS is gone.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $args
     * @return array<string, mixed>|null
     */
    private function awsJson(array $args): ?array
    {
        $process = new Process(array_merge(['aws'], $args, ['--output', 'json']));
        $process->setTimeout(120);
        $process->run();
        if (! $process->isSuccessful()) {
            return null;
        }
        $decoded = json_decode($process->getOutput(), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  list<string>  $args
     */
    private function awsOk(array $args): bool
    {
        $process = new Process(array_merge(['aws'], $args));
        $process->setTimeout(180);
        $process->run();
        if (! $process->isSuccessful()) {
            $this->error(trim($process->getErrorOutput() ?: $process->getOutput()));

            return false;
        }

        return true;
    }
}
