<?php
/** @var array $report @var string $from @var string $to @var string $pageTitle */
$reportType = 'warranty';
$columns = $columns ?? array_keys($report['rows'][0] ?? []);
$columnLabels = $columnLabels ?? [];
$totalKey = $totalKey ?? null;
require __DIR__ . '/_generic.php';
