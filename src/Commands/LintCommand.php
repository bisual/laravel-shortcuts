<?php

declare(strict_types=1);

namespace Bisual\LaravelShortcuts\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Number;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;

final class LintCommand extends Command
{
    protected $signature = 'lint';

    protected $description = 'Lint PHP files using Pint and Rector, comparing against a selected branch';

    public function handle(): int
    {
        $branches = $this->getCompareBranches();

        if ($branches->isEmpty()) {
            $this->error('No branches found. Please ensure you have main, develop or sprint branches available.');

            return self::FAILURE;
        }

        $baseBranch = select(
            label: 'Branch to compare against',
            options: $branches->toArray(),
            default: $branches->contains('main') ? 'main' : 'develop'
        );

        if (! $this->validateBranch($baseBranch)) {
            return self::FAILURE;
        }

        $startTime = now();

        $changedFiles = $this->getChangedPhpFiles($baseBranch);
        $validFiles = $this->filterValidFiles($changedFiles);

        $rectorTime = 0;
        $pintTime = 0;

        if ($validFiles->isNotEmpty()) {
            $this->info('🔧 Running Rector on '.$validFiles->count().' file(s)...');
            $rectorStartTime = now();
            $rectorCommand = 'vendor/bin/rector process '.$validFiles->map(fn (string $file): string => escapeshellarg($file))->implode(' ');
            passthru($rectorCommand);
            $rectorTime = $rectorStartTime->diffInSeconds(now(), absolute: true);
            $this->info('✅ Rector completed in '.Number::format($rectorTime, 2).' seconds');
        } else {
            $this->info('ℹ️  No PHP files to process with Rector');
        }

        $this->info('🎨 Running Pint against branch: '.$baseBranch.'...');
        $pintStartTime = now();
        passthru('./vendor/bin/pint --diff='.escapeshellarg((string) $baseBranch));
        $pintTime = $pintStartTime->diffInSeconds(now(), absolute: true);
        $this->info('✅ Pint completed in '.Number::format($pintTime, 2).' seconds');

        $totalTime = $startTime->diffInSeconds(now(), absolute: true);

        $this->newLine();
        table(
            ['Tool', 'Status', 'Time', 'Files'],
            collect([
                [
                    'Rector',
                    $validFiles->isNotEmpty() ? '✅ Completed' : '⏭️  Skipped',
                    Number::format($rectorTime, 2).'s',
                    $validFiles->isNotEmpty() ? (string) $validFiles->count() : '0',
                ],
                [
                    'Pint',
                    '✅ Completed',
                    Number::format($pintTime, 2).'s',
                    (string) $changedFiles->count(),
                ],
                [
                    'Total',
                    '✨ Completed',
                    Number::format($totalTime, 2).'s',
                    '-',
                ],
            ])->toArray()
        );

        $hasChanges = Process::run('git status --porcelain')->output() !== '';

        if ($hasChanges) {
            $wantsToCommitChanges = confirm('Do you want to commit the changes? (commit message: "refactor: composer lint")', default: true);

            if ($wantsToCommitChanges) {
                $this->info('Staging all changes...');
                Process::run('git add -A')->throw();

                $this->info('Committing changes...');
                Process::run('git commit -m "refactor: composer lint"')->throw();

                $this->info('✅ Changes committed successfully, don\'t forget to push them (;');
            }
        }

        return self::SUCCESS;
    }

    private function getCompareBranches(): Collection
    {
        $localBranchesResult = Process::run('git branch --format="%(refname:short)"');
        $localBranches = collect(str($localBranchesResult->output())->trim()->explode("\n"))->filter();

        $remoteBranchesResult = Process::run('git branch -r --format="%(refname:short)"');
        $remoteBranches = collect(str($remoteBranchesResult->output())->trim()->explode("\n"))->filter();

        $allBranches = $localBranches->merge($remoteBranches)->unique();

        $branches = $allBranches
            ->map(fn (string $branch): string => str_replace('origin/', '', $branch))
            ->filter(fn (string $branch): bool => in_array($branch, ['main', 'develop'], true) || preg_match('/^sprint_\d+/', $branch) === 1)
            ->unique();

        $mainBranch = $branches->first(fn (string $branch): bool => $branch === 'main');
        $developBranch = $branches->first(fn (string $branch): bool => $branch === 'develop');
        $sprintBranches = $branches->reject(fn (string $branch): bool => $branch === 'main' || $branch === 'develop');

        $sprintBranches = $sprintBranches
            ->sortByDesc(function (string $branch): int {
                preg_match('/sprint_(\d+)/', $branch, $matches);

                return (int) ($matches[1] ?? 0);
            })
            ->take(4);

        $sortedBranches = collect();
        if ($mainBranch !== null) {
            $sortedBranches->push($mainBranch);
        }
        if ($developBranch !== null) {
            $sortedBranches->push($developBranch);
        }

        return $sortedBranches->merge($sprintBranches)->values();
    }

    private function validateBranch(string $branch): bool
    {
        $localCheck = Process::run("git show-ref --verify --quiet refs/heads/{$branch}");
        $remoteCheck = Process::run("git show-ref --verify --quiet refs/remotes/origin/{$branch}");

        if ($localCheck->failed() && $remoteCheck->failed()) {
            $this->error("Branch '{$branch}' does not exist");

            return false;
        }

        return true;
    }

    private function getChangedPhpFiles(string $baseBranch): Collection
    {
        $result = Process::run("git diff --name-only \"{$baseBranch}\"...HEAD -- '*.php'");

        if ($result->failed()) {
            return collect();
        }

        return collect(str($result->output())->trim()->explode("\n"))->filter();
    }

    private function filterValidFiles(Collection $files): Collection
    {
        return $files
            ->filter(fn (string $file): bool => is_file($file))
            ->filter(fn (string $file): bool => (bool) preg_match('/^(src|config|database|tests)\/.*\.php$/', $file));
    }
}
