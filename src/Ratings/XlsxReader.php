<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Минимальный читатель .xlsx (первый лист) без внешних зависимостей —
 * xlsx это ZIP-архив с XML внутри (см. bin/debug_rating_page.php,
 * которым это и было впервые обнаружено на реальных выгрузках НРА/НКР).
 * Возвращает строки как ассоциативные массивы "имя колонки => значение",
 * имя колонки берётся из первой строки листа (заголовок) — так же, как
 * IssClient::block() превращает columns+data ISS API в assoc-массивы.
 *
 * Namespace-агностичный: SimpleXML у документа с одним default-namespace
 * (spreadsheetml) отдаёт дочерние элементы через обычный ->children()
 * без указания namespace — проверено на реальных выгрузках
 * ratings.ru/issuers.php и ra-national.ru (см. STAGE3_RATINGS.md).
 */
final class XlsxReader
{
    /** @return array<int, array<string, string>> */
    public static function readFirstSheetAsRows(string $filePath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new RuntimeException("Не удалось открыть xlsx как ZIP: {$filePath}");
        }

        $sharedStrings = self::readSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetXml === false) {
            throw new RuntimeException("В xlsx нет xl/worksheets/sheet1.xml: {$filePath}");
        }

        $rawRows = self::parseSheet($sheetXml, $sharedStrings);
        if ($rawRows === []) {
            return [];
        }

        $header = array_shift($rawRows);
        $result = [];
        foreach ($rawRows as $rawRow) {
            $namedRow = [];
            foreach ($header as $col => $name) {
                if ($name === '') {
                    continue;
                }
                $namedRow[$name] = $rawRow[$col] ?? '';
            }
            $result[] = $namedRow;
        }

        return $result;
    }

    /** @return array<int, string> */
    private static function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $doc = new SimpleXMLElement($xml);
        $strings = [];
        foreach ($doc->si as $si) {
            // Одна <si> может состоять из нескольких форматированных "кусков"
            // (<r><t>...</t></r>) — конкатенируем все <t> внутри неё, а не
            // просто берём один. Проверено: на реальных выгрузках НРА/НКР
            // такого не встретилось (число <si> == число <t>), но полагаться
            // на это молча — риск тихо потерять хвост строки на будущей выгрузке.
            $texts = $si->xpath('.//*[local-name()="t"]');
            $strings[] = implode('', array_map(static fn ($t) => (string) $t, $texts ?: []));
        }

        return $strings;
    }

    /**
     * @param array<int, string> $sharedStrings
     * @return array<int, array<string, string>> строки, ключ — буква колонки (A, B, ...)
     */
    private static function parseSheet(string $xml, array $sharedStrings): array
    {
        $doc = new SimpleXMLElement($xml);
        $rows = [];

        foreach ($doc->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];
                $col = preg_replace('/\d+/', '', $ref) ?? $ref;
                $type = (string) $cell['t'];

                if ($type === 'inlineStr') {
                    // Строка хранится прямо в ячейке (<c t="inlineStr"><is><t>...</t></is></c>),
                    // а не через ссылку на sharedStrings.xml — так пишут, например,
                    // openpyxl и другие генераторы xlsx (не только Excel/LibreOffice).
                    // Без этой ветки такие ячейки молча читались бы как пустая строка.
                    $texts = $cell->xpath('.//*[local-name()="t"]');
                    $value = implode('', array_map(static fn ($t) => (string) $t, $texts ?: []));
                } else {
                    $value = isset($cell->v) ? (string) $cell->v : '';
                    if ($type === 's' && $value !== '') {
                        $value = $sharedStrings[(int) $value] ?? '';
                    }
                }

                $cells[$col] = $value;
            }
            $rows[] = $cells;
        }

        return $rows;
    }
}
