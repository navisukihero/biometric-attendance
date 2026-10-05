<?php

declare(strict_types=1);

require __DIR__ . '/../includes/report_word.php';

function word_test(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$headings = ['Employee ID', 'Employee', 'Date', 'Reason', 'Net Pay'];
$rows = [
    ['EMP-TEST-1', 'Ana & Ben <Faculty>', '2026-10-01', 'Covering "class"', '5400.00'],
    ['EMP-TEST-2', '=unsafe-name', '2026-10-02', 'Approved work', '250.25'],
];
$xml = report_word_document('Sample Payroll Report', 'Period: 2026-10-01 to 2026-10-02', $headings, $rows);
word_test(str_contains($xml, '<w:pgSz w:w="11906" w:h="16838"/>'), 'A4 portrait page size is required');
word_test(str_contains($xml, 'Ana &amp; Ben &lt;Faculty&gt;'), 'User-provided values must be XML-escaped');
word_test(!str_contains($xml, '<Faculty>'), 'User-provided markup must never enter document XML');
word_test(str_contains($xml, '₱5,400.00'), 'Payroll money fields must have readable currency formatting');
word_test(substr_count($xml, '<w:tbl>') === 2, 'Each record must have its own readable table');

$archive = report_word_package('Sample Payroll Report', 'Period: 2026-10-01 to 2026-10-02', $headings, $rows);
word_test(substr($archive, 0, 4) === "PK\x03\x04", 'Word output must be a DOCX ZIP package');
word_test(str_contains($archive, 'word/document.xml'), 'DOCX must contain a Word document part');

if (isset($argv[1])) {
    word_test(file_put_contents($argv[1], $archive) === strlen($archive), 'Sample DOCX could not be written');
}
echo "report_word_test: PASS\n";
