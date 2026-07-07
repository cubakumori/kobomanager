<?php
/**
 * Escritor .xlsx MÍNIMO y sin dependencias (un .xlsx es un ZIP de partes XML;
 * PHP trae ZipArchive). Pensado para la exportación de envíos: la hoja se
 * escribe fila a fila a un archivo temporal (memoria O(1 fila), como el CSV en
 * streaming), y al cerrar se empaqueta el ZIP y se vuelca a la salida.
 *
 * Cubre solo lo que necesita el export: una hoja, celdas de texto (inline
 * strings) o numéricas. Sin estilos por celda, sin fórmulas — precisamente por
 * eso una celda de texto que empiece por «=» NO es una fórmula (a diferencia del
 * CSV), así que no hace falta neutralizar inyección.
 *
 * Uso:
 *   $w = new XlsxWriter();
 *   $w->addRow(['Cabecera A', 'Cabecera B']);   // strings
 *   $w->addRow(['texto', 42]);                   // int/float => celda numérica
 *   $w->stream('datos.xlsx');                    // emite y limpia (hace exit en el llamador)
 */
class XlsxWriter {
    private string $sheetPath;
    /** @var resource */
    private $fh;
    private int $rowNum = 0;

    public function __construct() {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('La extensión zip de PHP no está disponible; no se puede generar .xlsx');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'km_xlsx_sheet_');
        if ($tmp === false) {
            throw new RuntimeException('No se pudo crear un archivo temporal para el .xlsx');
        }
        $this->sheetPath = $tmp;
        $this->fh = fopen($this->sheetPath, 'w');
        fwrite(
            $this->fh,
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        );
    }

    /**
     * Añade una fila. Cada valor: int|float → celda numérica; null|'' → celda
     * vacía; el resto → texto (inline string). Los valores no escalares deben
     * venir ya convertidos a string por el llamador.
     */
    public function addRow(array $cells): void {
        $this->rowNum++;
        $r = $this->rowNum;
        $buf = '<row r="' . $r . '">';
        $col = 0;
        foreach ($cells as $v) {
            $ref = self::colLetter($col) . $r;
            $col++;
            if (is_int($v) || is_float($v)) {
                $buf .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
                continue;
            }
            $s = (string) ($v ?? '');
            if ($s === '') {
                $buf .= '<c r="' . $ref . '"/>';
                continue;
            }
            $buf .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                  . self::esc($s) . '</t></is></c>';
        }
        $buf .= '</row>';
        fwrite($this->fh, $buf);
    }

    /**
     * Cierra la hoja, empaqueta el ZIP .xlsx, emite las cabeceras HTTP de descarga
     * y vuelca el archivo a la salida. Limpia los temporales. No retorna nada útil;
     * el llamador debería hacer `exit` a continuación.
     */
    public function stream(string $filename): void {
        fwrite($this->fh, '</sheetData></worksheet>');
        fclose($this->fh);

        $zipPath = tempnam(sys_get_temp_dir(), 'km_xlsx_zip_');
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            '</Types>');
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>');
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ' .
            'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets><sheet name="Datos" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
            '</Relationships>');
        // Estilos mínimos: un único formato por defecto (evita el aviso de «reparar»
        // de algunos lectores). Ninguna celda referencia estilos (s=), así que basta.
        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>' .
            '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>' .
            '<borders count="1"><border/></borders>' .
            '<cellStyleXfs count="1"><xf/></cellStyleXfs>' .
            '<cellXfs count="1"><xf/></cellXfs>' .
            '</styleSheet>');
        $zip->addFile($this->sheetPath, 'xl/worksheets/sheet1.xml');
        $zip->close();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Content-Length: ' . (string) filesize($zipPath));
        readfile($zipPath);

        @unlink($zipPath);
        @unlink($this->sheetPath);
    }

    /** Índice de columna (0-based) → letra(s) de columna A1 («A», «Z», «AA»…). */
    private static function colLetter(int $i): string {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26) . $s;
        }
        return $s;
    }

    /** Escapa para XML y elimina los caracteres de control ilegales en XML 1.0. */
    private static function esc(string $s): string {
        // XML 1.0 solo admite \t \n \r y >= 0x20 (más los rangos Unicode válidos).
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? $s;
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
