<?php

declare(strict_types=1);

/** Word exports use the same headings and rows as the existing CSV reports. */
function report_word_xml(mixed $value): string
{
    $text = (string) ($value ?? '');
    $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', ' ', $text) ?? '';
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function report_word_run(mixed $value, bool $bold = false, int $halfPoints = 19): string
{
    return '<w:r><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/>'
        . ($bold ? '<w:b/>' : '')
        . '<w:color w:val="111111"/><w:sz w:val="' . $halfPoints . '"/></w:rPr>'
        . '<w:t xml:space="preserve">' . report_word_xml($value) . '</w:t></w:r>';
}

function report_word_paragraph(mixed $value, bool $bold = false, int $halfPoints = 19, int $after = 0, bool $keepNext = false): string
{
    return '<w:p><w:pPr><w:spacing w:after="' . $after . '"/>'
        . ($keepNext ? '<w:keepNext/>' : '')
        . '</w:pPr>' . report_word_run($value, $bold, $halfPoints) . '</w:p>';
}

function report_word_cell(mixed $value, int $width, bool $bold = false, bool $shaded = false): string
{
    return '<w:tc><w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/>'
        . ($shaded ? '<w:shd w:fill="E8EDF1"/>' : '')
        . '<w:tcMar><w:top w:w="70" w:type="dxa"/><w:left w:w="110" w:type="dxa"/>'
        . '<w:bottom w:w="70" w:type="dxa"/><w:right w:w="110" w:type="dxa"/></w:tcMar></w:tcPr>'
        . report_word_paragraph($value, $bold, 18) . '</w:tc>';
}

/** @param list<string> $headings @param list<array<int, scalar|null>> $rows */
function report_word_document(string $title, string $period, array $headings, array $rows): string
{
    $body = report_word_paragraph('UBAY COMMUNITY COLLEGE', true, 20, 80, true);
    $body .= '<w:p><w:pPr><w:pStyle w:val="Title"/><w:spacing w:after="90"/>'
        . '<w:keepNext/></w:pPr>' . report_word_run($title, true, 32) . '</w:p>';
    $body .= report_word_paragraph($period, false, 19, 40);
    $body .= report_word_paragraph('Records: ' . count($rows) . '    Generated: ' . date('Y-m-d H:i') . ' (Manila)', false, 18, 200);

    if (!$rows) {
        $body .= report_word_paragraph('No records found for this report.', false, 20);
    }

    $nameIndex = array_search('Employee', $headings, true);
    $dateIndex = array_search('Date', $headings, true);
    if ($dateIndex === false) {
        $dateIndex = array_search('Attendance Date', $headings, true);
    }
    foreach ($rows as $index => $row) {
        $recordLabel = 'Record ' . ($index + 1);
        if ($nameIndex !== false && !empty($row[$nameIndex])) {
            $recordLabel .= '  |  ' . (string) $row[$nameIndex];
        }
        if ($dateIndex !== false && !empty($row[$dateIndex])) {
            $recordLabel .= '  |  ' . (string) $row[$dateIndex];
        }
        $body .= '<w:tbl><w:tblPr><w:tblW w:w="10206" w:type="dxa"/><w:tblLayout w:type="fixed"/>'
            . '<w:tblBorders><w:top w:val="single" w:sz="4" w:color="D9D9D9"/>'
            . '<w:left w:val="single" w:sz="4" w:color="D9D9D9"/>'
            . '<w:bottom w:val="single" w:sz="4" w:color="D9D9D9"/>'
            . '<w:right w:val="single" w:sz="4" w:color="D9D9D9"/>'
            . '<w:insideH w:val="single" w:sz="4" w:color="D9D9D9"/>'
            . '<w:insideV w:val="single" w:sz="4" w:color="D9D9D9"/>'
            . '</w:tblBorders></w:tblPr><w:tblGrid><w:gridCol w:w="3600"/><w:gridCol w:w="6606"/></w:tblGrid>';
        $body .= '<w:tr><w:trPr><w:tblHeader/><w:cantSplit/></w:trPr>'
            . report_word_cell($recordLabel, 3600, true, true)
            . report_word_cell('Report details', 6606, true, true) . '</w:tr>';
        foreach ($headings as $column => $heading) {
            $value = $row[$column] ?? '';
            if ($value !== '' && is_numeric($value) && in_array($heading, [
                'Approved Rate', 'Calculated Basic Salary', 'Regular Pay Snapshot',
                'Hourly Equivalent', 'Late Deduction', 'Undertime Deduction',
                'Half-Day Deduction', 'Absence / Unpaid-Day Deduction', 'Cash Advance',
                'Other Deductions', 'OT Rate', 'OT Pay', 'Holiday Pay', 'Rest-Day Pay',
                'Other Earnings', 'Gross Pay', 'Total Deductions', 'Net Pay', 'Gross',
            ], true)) {
                $value = '₱' . number_format((float) $value, 2);
            }
            $body .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>'
                . report_word_cell($heading, 3600, true)
                . report_word_cell($value, 6606) . '</w:tr>';
        }
        $body .= '</w:tbl>' . report_word_paragraph('', false, 18, 140);
    }

    // ISO A4 portrait in twentieths of a point (twips); margins are 15 mm.
    $body .= '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
        . '<w:pgMar w:top="850" w:right="850" w:bottom="850" w:left="850"'
        . ' w:header="0" w:footer="0" w:gutter="0"/></w:sectPr>';
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body>' . $body . '</w:body></w:document>';
}

/** Build a small standards-compliant DOCX without requiring XAMPP's optional ZIP extension. */
function report_word_zip(array $files): string
{
    $output = '';
    $directory = '';
    $offset = 0;
    foreach ($files as $name => $data) {
        $nameLength = strlen($name);
        $size = strlen($data);
        $crc = crc32($data);
        $output .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLength, 0)
            . $name . $data;
        $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0,
            $crc, $size, $size, $nameLength, 0, 0, 0, 0, 0, $offset) . $name;
        $offset += 30 + $nameLength + $size;
    }
    $count = count($files);
    return $output . $directory
        . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($directory), $offset, 0);
}

/** @param list<string> $headings @param list<array<int, scalar|null>> $rows */
function report_word_package(string $title, string $period, array $headings, array $rows): string
{
    $document = report_word_document($title, $period, $headings, $rows);
    return report_word_zip([
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>',
        'word/document.xml' => $document,
        'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'word/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/>'
            . '<w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:b/>'
            . '<w:color w:val="000000"/><w:sz w:val="32"/></w:rPr></w:style></w:styles>',
    ]);
}

/** @param list<string> $headings @param list<array<int, scalar|null>> $rows */
function report_word(string $filename, string $title, string $period, array $headings, array $rows): never
{
    $archive = report_word_package($title, $period, $headings, $rows);
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($archive));
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo $archive;
    exit;
}
