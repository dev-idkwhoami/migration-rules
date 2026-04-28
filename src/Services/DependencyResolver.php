<?php

declare(strict_types=1);

namespace Idkwhoami\MigrationRules\Services;

use Idkwhoami\MigrationRules\Exceptions\MigrationCycleException;
use Idkwhoami\MigrationRules\Exceptions\MissingDependencyException;

class DependencyResolver
{
    /**
     * Build directed graph and return topologically sorted order.
     *
     * @param  array<string, array<string, mixed>>  $manifest
     * @return array<string> Ordered table names
     *
     * @throws MigrationCycleException
     * @throws MissingDependencyException
     */
    public function resolve(array $manifest): array
    {
        $graph = $this->buildGraph($manifest);
        $this->detectCycles($graph, $manifest);

        return $this->topologicalSort($manifest, $graph);
    }

    /**
     * @return array<string, array<string>>
     */
    private function buildGraph(array $manifest): array
    {
        $graph = [];

        foreach ($manifest as $tableName => $entry) {
            if (! isset($graph[$tableName])) {
                $graph[$tableName] = [];
            }
            foreach ($entry['has_fks'] as $fkTarget) {
                // Edge: this table depends on fkTarget
                if (! isset($graph[$tableName])) {
                    $graph[$tableName] = [];
                }
                $graph[$tableName][] = $fkTarget;
            }
        }

        return $graph;
    }

    /**
     * @throws MigrationCycleException
     */
    private function detectCycles(array $graph, array $manifest): void
    {
        $visited = [];
        $recStack = [];

        foreach (array_keys($graph) as $node) {
            if (! isset($visited[$node])) {
                $this->dfsDetectCycle($node, $graph, $visited, $recStack, $manifest, []);
            }
        }
    }

    /**
     * @param  array<string, array<string>>  $graph
     * @param  array<string, array<string, mixed>>  $manifest
     * @param  array<string>  $path
     *
     * @throws MigrationCycleException
     */
    private function dfsDetectCycle(
        string $node,
        array $graph,
        array &$visited,
        array &$recStack,
        array $manifest,
        array $path
    ): void {
        $visited[$node] = true;
        $recStack[$node] = true;
        $path[] = $node;

        foreach ($graph[$node] ?? [] as $neighbor) {
            // Skip external tables
            if (! isset($manifest[$neighbor])) {
                continue;
            }

            if (! isset($visited[$neighbor])) {
                $this->dfsDetectCycle($neighbor, $graph, $visited, $recStack, $manifest, $path);
            } elseif (isset($recStack[$neighbor])) {
                $cycleStart = array_search($neighbor, $path);
                $cyclePath = array_slice($path, $cycleStart);
                $cyclePath[] = $neighbor;

                throw new MigrationCycleException(
                    'Cycle detected: '.implode(' -> ', $cyclePath),
                    $cyclePath
                );
            }
        }

        unset($recStack[$node]);
    }

    /**
     * Kahn's algorithm for topological sort.
     *
     * @param  array<string, array<string, mixed>>  $manifest
     * @param  array<string, array<string>>  $graph
     * @return array<string>
     */
    private function topologicalSort(array $manifest, array $graph): array
    {
        $inDegree = [];
        foreach (array_keys($manifest) as $table) {
            $inDegree[$table] = 0;
        }

        foreach ($graph as $from => $targets) {
            foreach ($targets as $to) {
                // Skip external tables
                if (! isset($inDegree[$to])) {
                    continue;
                }
                $inDegree[$to]++;
            }
        }

        $queue = [];
        foreach ($inDegree as $table => $degree) {
            if ($degree === 0) {
                $queue[] = $table;
            }
        }

        $sorted = [];
        while (! empty($queue)) {
            $node = array_shift($queue);
            $sorted[] = $node;

            foreach ($graph[$node] ?? [] as $neighbor) {
                if (! isset($inDegree[$neighbor])) {
                    continue;
                }
                $inDegree[$neighbor]--;
                if ($inDegree[$neighbor] === 0) {
                    $queue[] = $neighbor;
                }
            }
        }

        // Check for cycles (if not all nodes are sorted)
        if (count($sorted) !== count($manifest)) {
            $unsorted = array_diff(array_keys($manifest), $sorted);
            throw new MigrationCycleException(
                'Cycle detected involving: '.implode(', ', $unsorted),
                $unsorted
            );
        }

        return $sorted;
    }
}
