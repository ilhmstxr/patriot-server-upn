/**
 * Multi-sheet ExcelJS Workbook Builder (100% client-side, readable in Excel/LibreOffice/Google Sheets).
 *
 * Sheet 1: "Dashboard Nilai"
 *   - Matches the reference dashboard table columns & styling
 *   - Conditional row background:
 *       * status PT = UNVERIFIED -> Red (#FF0000)
 *       * skor akhir penilaian PT = 0 -> Orange (#FFA500)
 *       * kosong / tidak ada atau skor > 0 -> White (#FFFFFF)
 *
 * Sheet 2..N+1: One sheet per PT
 *   - Mirrors resources/views/pdf/resume.blade.php:
 *       * Profil Perguruan Tinggi + Predikat Bintang + Total Skor
 *       * Ringkasan Capaian 3 Variabel + Pie Chart Kontribusi Variabel
 *       * Catatan Penilaian
 *       * Tabel & Radar Chart Patriotisme Kebijakan (A.1 - A.5)
 *       * Tabel & Radar Chart Patriotisme Kelembagaan (B.1 - B.20)
 *       * Tabel & Radar Chart Patriotisme Mahasiswa (C.1 - C.15)
 */

import {
    calculateRubrikForPt,
    formatBintangLabel,
    makeUniqueSheetName,
    resolveBintang,
    resolveDashboardRowTone,
} from './rubrik-calculator.js';

const FONT_FAMILY = 'Arial';
const NUM_FMT_DECIMAL = '#,##0.00';
const NUM_FMT_INT = '#,##0';

const THIN_BORDER_GRAY = {
    top: { style: 'thin', color: { argb: 'FFD9D9D9' } },
    left: { style: 'thin', color: { argb: 'FFD9D9D9' } },
    bottom: { style: 'thin', color: { argb: 'FFD9D9D9' } },
    right: { style: 'thin', color: { argb: 'FFD9D9D9' } },
};

const THIN_BORDER_DARK = {
    top: { style: 'thin', color: { argb: 'FF222222' } },
    left: { style: 'thin', color: { argb: 'FF222222' } },
    bottom: { style: 'thin', color: { argb: 'FF222222' } },
    right: { style: 'thin', color: { argb: 'FF222222' } },
};

const STAR_STYLES = {
    1: { fontColor: 'FFB45309', fillColor: 'FFFEF9E7' },
    2: { fontColor: 'FF1D4ED8', fillColor: 'FFEFF6FF' },
    3: { fontColor: 'FF0F766E', fillColor: 'FFF0FDFA' },
    4: { fontColor: 'FF15803D', fillColor: 'FFF0FDF4' },
    5: { fontColor: 'FF166534', fillColor: 'FFDCFCE7' },
};

const RADAR_PALETTES = {
    variabel_1: {
        strokeColor: '#2D6D25',
        fillColor: 'rgba(45, 109, 37, 0.18)',
        gridColor: '#CAD8C8',
        centerDotColor: '#7A8F77',
    },
    variabel_2: {
        strokeColor: '#DF692A',
        fillColor: 'rgba(223, 105, 42, 0.18)',
        gridColor: '#E0CFC7',
        centerDotColor: '#A88C7F',
    },
    variabel_3: {
        strokeColor: '#E59800',
        fillColor: 'rgba(229, 152, 0, 0.18)',
        gridColor: '#EDD9B8',
        centerDotColor: '#B89B6A',
    },
};

function solidFill(argb) {
    return {
        type: 'pattern',
        pattern: 'solid',
        fgColor: { argb },
    };
}

function estimateRowHeight(text, charsPerLine, minHeight = 20, lineHeight = 14) {
    if (!text) return minHeight;
    const paragraphs = String(text).split(/\r?\n/);
    let totalLines = 0;
    for (const p of paragraphs) {
        totalLines += Math.max(1, Math.ceil(p.length / charsPerLine));
    }
    return Math.max(minHeight, totalLines * lineHeight + 8);
}

function escapeExcelFormulaString(str) {
    return String(str ?? '').replace(/"/g, '""');
}

function escapeSheetRef(sheetName) {
    return `'${String(sheetName).replace(/'/g, "''")}'`;
}

function applyRangeBorder(ws, startRow, startCol, endRow, endCol, border = THIN_BORDER_DARK) {
    for (let r = startRow; r <= endRow; r++) {
        for (let c = startCol; c <= endCol; c++) {
            ws.getCell(r, c).border = border;
        }
    }
}

function getStatusBadgeStyle(status) {
    switch (String(status || '').toUpperCase()) {
        case 'UNVERIFIED':
            return { fill: 'FFFEE2E2', font: 'FFB91C1C' };
        case 'GRADED':
        case 'PUBLISHED':
            return { fill: 'FFDCFCE7', font: 'FF166534' };
        case 'SUBMITTED':
        case 'FINALIZED':
            return { fill: 'FFFEF3C7', font: 'FFB45309' };
        case 'IN_PROGRESS':
        case 'VALIDATING':
            return { fill: 'FFDBEAFE', font: 'FF1D4ED8' };
        default:
            return { fill: 'FFF3F4F6', font: 'FF374151' };
    }
}

/**
 * Yield to browser event loop so progress bar updates and tab stays responsive.
 */
function yieldToMainThread() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

/**
 * Build complete multi-sheet ExcelJS Workbook.
 *
 * @param {object} options
 * @param {any} options.ExcelJS - ExcelJS module
 * @param {Array} options.items - All PT items fetched via chunked API
 * @param {Array} options.rubrikSchema - Rubric definition from /export-meta
 * @param {object|null} options.chartRenderer - Chart renderer instance (CanvasChartRenderer or mock in tests)
 * @param {AbortSignal} [options.signal] - Optional abort signal for user cancellation
 * @param {function} [options.onProgress] - Callback ({ current, total, label })
 */
export async function buildDaftarNilaiWorkbook({
    ExcelJS,
    items,
    rubrikSchema,
    chartRenderer = null,
    signal = null,
    onProgress = null,
}) {
    const workbook = new ExcelJS.Workbook();
    workbook.creator = 'Patriot Metric Admin';
    workbook.created = new Date();
    workbook.modified = new Date();
    workbook.calcProperties.fullCalcOnLoad = true;

    const usedSheetNames = new Set(['dashboard nilai']);

    // Pre-assign unique sheet names for every PT so Sheet 1 can link directly to each PT sheet
    const ptSheetNames = items.map((pt, idx) =>
        makeUniqueSheetName(idx + 1, pt.nama_institusi, usedSheetNames)
    );

    // =========================================================================
    // SHEET 1: DASHBOARD NILAI
    // =========================================================================
    const dashSheet = workbook.addWorksheet('Dashboard Nilai', {
        properties: { tabColor: { argb: 'FF2F6D28' } },
        views: [{ state: 'frozen', ySplit: 1, showGridLines: true }],
        pageSetup: {
            orientation: 'landscape',
            fitToPage: true,
            fitToWidth: 1,
            fitToHeight: 0,
            paperSize: 9, // A4
        },
    });

    dashSheet.columns = [
        { header: 'No', key: 'no', width: 6 },
        { header: 'Nama Perguruan Tinggi', key: 'nama_pt', width: 44 },
        { header: 'Rata-Rata Nilai', key: 'rata_rata', width: 17 },
        { header: 'Perolehan Bintang', key: 'bintang', width: 19 },
        { header: 'Nama Penilai 1', key: 'penilai_1', width: 34 },
        { header: 'Nilai P1', key: 'nilai_p1', width: 12 },
        { header: 'Nama Penilai 2', key: 'penilai_2', width: 34 },
        { header: 'Nilai P2', key: 'nilai_p2', width: 12 },
        { header: 'Nilai Tahun Lalu', key: 'nilai_lalu', width: 16 },
        { header: 'Perolehan Bintang Tahun Lalu', key: 'bintang_lalu', width: 22 },
    ];

    // Style Header Row (matching reference screenshot: grey header, bold dark text, centered)
    const headerRow = dashSheet.getRow(1);
    headerRow.height = 32;
    for (let col = 1; col <= 10; col++) {
        const cell = headerRow.getCell(col);
        cell.fill = solidFill('FF808080');
        cell.font = { name: FONT_FAMILY, size: 10.5, bold: true, color: { argb: 'FF000000' } };
        cell.alignment = { vertical: 'middle', horizontal: 'center', wrapText: true };
        cell.border = {
            top: { style: 'thin', color: { argb: 'FF595959' } },
            left: { style: 'thin', color: { argb: 'FF595959' } },
            bottom: { style: 'medium', color: { argb: 'FF404040' } },
            right: { style: 'thin', color: { argb: 'FF595959' } },
        };
    }

    // Populate Dashboard rows
    items.forEach((pt, idx) => {
        const rowNum = idx + 2;
        const targetSheetName = ptSheetNames[idx];
        const tone = resolveDashboardRowTone(pt);

        const displayScore = pt.total_skor_akhir !== null && pt.total_skor_akhir !== undefined
            ? Number(pt.total_skor_akhir)
            : (pt.nilai_rata_rata !== null && pt.nilai_rata_rata !== undefined ? Number(pt.nilai_rata_rata) : null);

        const bintangNum = pt.bintang ?? resolveBintang(displayScore, pt.status);
        const bintangLaluNum = Number(pt.bintang_tahun_lalu || 0);

        const nilaiP1 = pt.nilai_p1 !== null && pt.nilai_p1 !== undefined ? Number(pt.nilai_p1) : null;
        const nilaiP2 = pt.nilai_p2 !== null && pt.nilai_p2 !== undefined ? Number(pt.nilai_p2) : null;
        const nilaiTahunLalu = pt.nilai_tahun_lalu !== null && pt.nilai_tahun_lalu !== undefined
            ? Number(pt.nilai_tahun_lalu)
            : null;

        const row = dashSheet.getRow(rowNum);
        const namaPtDisplay = pt.nama_institusi || '-';

        // Clickable Excel formula link to PT detail sheet (zero XML relationship corruption)
        const targetRef = escapeExcelFormulaString(`#${escapeSheetRef(targetSheetName)}!A1`);
        const sheetLinkFormula = `HYPERLINK("${targetRef}","${escapeExcelFormulaString(namaPtDisplay)}")`;

        row.getCell(1).value = idx + 1;
        row.getCell(2).value = { formula: sheetLinkFormula, result: namaPtDisplay };
        row.getCell(3).value = displayScore;
        row.getCell(4).value = formatBintangLabel(bintangNum);
        row.getCell(5).value = pt.nama_penilai_1 || '';
        row.getCell(6).value = nilaiP1;
        row.getCell(7).value = pt.nama_penilai_2 || '';
        row.getCell(8).value = nilaiP2;
        row.getCell(9).value = nilaiTahunLalu;
        row.getCell(10).value = formatBintangLabel(bintangLaluNum);

        // Auto-fit row height for wrapped names
        const hNama = estimateRowHeight(namaPtDisplay, 40, 22, 14);
        const hP1 = estimateRowHeight(pt.nama_penilai_1, 30, 22, 14);
        const hP2 = estimateRowHeight(pt.nama_penilai_2, 30, 22, 14);
        row.height = Math.max(hNama, hP1, hP2);

        // Row background & base text color per Requirement #2
        let rowFillArgb = 'FFFFFFFF'; // Putih (default / kosong / normal > 0)
        let rowTextArgb = 'FF111111';

        if (tone === 'red') {
            rowFillArgb = 'FFFF0000'; // Merah (UNVERIFIED)
            rowTextArgb = 'FFFFFFFF';
        } else if (tone === 'orange') {
            rowFillArgb = 'FFFFA500'; // Oranye (Skor akhir = 0)
            rowTextArgb = 'FF111111';
        }

        for (let col = 1; col <= 10; col++) {
            const cell = row.getCell(col);
            cell.fill = solidFill(rowFillArgb);
            cell.border = THIN_BORDER_GRAY;
            cell.font = {
                name: FONT_FAMILY,
                size: 10,
                bold: col === 3,
                color: { argb: rowTextArgb },
            };
        }

        // Column alignments & number formats
        row.getCell(1).alignment = { vertical: 'middle', horizontal: 'center' };
        row.getCell(2).alignment = { vertical: 'middle', horizontal: 'left', wrapText: true };
        row.getCell(3).alignment = { vertical: 'middle', horizontal: 'right' };
        row.getCell(3).numFmt = NUM_FMT_DECIMAL;
        row.getCell(4).alignment = { vertical: 'middle', horizontal: 'center' };
        row.getCell(5).alignment = { vertical: 'middle', horizontal: 'left', wrapText: true };
        row.getCell(6).alignment = { vertical: 'middle', horizontal: 'right' };
        row.getCell(6).numFmt = NUM_FMT_DECIMAL;
        row.getCell(7).alignment = { vertical: 'middle', horizontal: 'left', wrapText: true };
        row.getCell(8).alignment = { vertical: 'middle', horizontal: 'right' };
        row.getCell(8).numFmt = NUM_FMT_DECIMAL;
        row.getCell(9).alignment = { vertical: 'middle', horizontal: 'right' };
        if (nilaiTahunLalu !== null) {
            row.getCell(9).numFmt = NUM_FMT_DECIMAL;
        }
        row.getCell(10).alignment = { vertical: 'middle', horizontal: 'center' };

        // Accent star styling on normal (white) rows, matching the reference image
        if (tone === 'white') {
            if (bintangNum > 0 && STAR_STYLES[bintangNum]) {
                const st = STAR_STYLES[bintangNum];
                row.getCell(4).font = { name: FONT_FAMILY, size: 10, bold: true, color: { argb: st.fontColor } };
                row.getCell(4).fill = solidFill(st.fillColor);
            }
            if (bintangLaluNum > 0 && STAR_STYLES[bintangLaluNum]) {
                const st = STAR_STYLES[bintangLaluNum];
                row.getCell(10).font = { name: FONT_FAMILY, size: 10, bold: true, color: { argb: st.fontColor } };
                row.getCell(10).fill = solidFill(st.fillColor);
            }
        }
    });

    const lastDataRow = Math.max(1, items.length + 1);
    dashSheet.autoFilter = `A1:J${lastDataRow}`;

    // Compact legend below table (separated by 1 empty row so AutoFilter stays cleanly on A1:J{lastDataRow})
    const legendStart = lastDataRow + 2;
    dashSheet.getCell(legendStart, 1).value = 'Keterangan Warna Baris:';
    dashSheet.getCell(legendStart, 1).font = { name: FONT_FAMILY, size: 9.5, bold: true, color: { argb: 'FF374151' } };
    dashSheet.mergeCells(legendStart, 1, legendStart, 3);

    const legendEntries = [
        { fill: 'FFFF0000', font: 'FFFFFFFF', badge: 'MERAH', desc: 'Status PT = UNVERIFIED (belum terverifikasi)' },
        { fill: 'FFFFA500', font: 'FF111111', badge: 'ORANYE', desc: 'Skor akhir penilaian PT = 0 (terverifikasi, nilai akhir 0,00)' },
        { fill: 'FFFFFFFF', font: 'FF111111', badge: 'PUTIH', desc: 'Skor akhir > 0 atau data skor kosong / belum tersedia' },
    ];

    legendEntries.forEach((entry, i) => {
        const r = legendStart + 1 + i;
        dashSheet.getRow(r).height = 19;

        const badgeCell = dashSheet.getCell(r, 1);
        badgeCell.value = entry.badge;
        badgeCell.fill = solidFill(entry.fill);
        badgeCell.font = { name: FONT_FAMILY, size: 8.5, bold: true, color: { argb: entry.font } };
        badgeCell.alignment = { vertical: 'middle', horizontal: 'center' };
        badgeCell.border = THIN_BORDER_DARK;

        const descCell = dashSheet.getCell(r, 2);
        descCell.value = entry.desc;
        descCell.font = { name: FONT_FAMILY, size: 9, color: { argb: 'FF374151' } };
        descCell.alignment = { vertical: 'middle', horizontal: 'left' };
        dashSheet.mergeCells(r, 2, r, 6);
    });

    // =========================================================================
    // SHEETS 2..N+1: PER-PT RUBRIC RESUME + EMBEDDED CHARTS
    // =========================================================================
    for (let idx = 0; idx < items.length; idx++) {
        if (signal?.aborted) {
            throw new DOMException('Export dibatalkan oleh pengguna.', 'AbortError');
        }

        const pt = items[idx];
        const sheetName = ptSheetNames[idx];

        if (onProgress) {
            onProgress({
                current: idx + 1,
                total: items.length,
                label: `Menyusun sheet & diagram (${idx + 1}/${items.length}): ${pt.nama_institusi || '-'}`,
            });
        }

        buildPtResumeSheet({
            workbook,
            pt,
            sheetName,
            rubrikSchema,
            chartRenderer,
        });

        // Yield every PT so browser UI stays silky smooth and never lags
        await yieldToMainThread();
    }

    return workbook;
}

/**
 * Build one PT detail sheet mirroring resources/views/pdf/resume.blade.php.
 */
function buildPtResumeSheet({ workbook, pt, sheetName, rubrikSchema, chartRenderer }) {
    const tone = resolveDashboardRowTone(pt);
    const tabColorArgb = tone === 'red' ? 'FFDC2626' : (tone === 'orange' ? 'FFF59E0B' : 'FF2F6D28');

    const ws = workbook.addWorksheet(sheetName, {
        properties: { tabColor: { argb: tabColorArgb } },
        views: [{ showGridLines: true }],
        pageSetup: {
            orientation: 'landscape',
            fitToPage: true,
            fitToWidth: 1,
            fitToHeight: 0,
            paperSize: 9, // A4
        },
    });

    // Consistent 13-column layout: A..H = Data Tables, I = Spacer, J..M = Charts & Predikat Card
    ws.columns = [
        { width: 7 },  // A: No
        { width: 28 }, // B: Label / Indikator (part 1)
        { width: 3 },  // C: ':' / Indikator (part 2)
        { width: 26 }, // D: Value / Indikator (part 3)
        { width: 11 }, // E: Skor R1 / Skor Saat Ini
        { width: 11 }, // F: Skor R2 / Skor Maks
        { width: 13 }, // G: Rata-Rata (0-5) / Capaian %
        { width: 12 }, // H: Skor Tertimbang / Kontribusi %
        { width: 3 },  // I: Gap
        { width: 14 }, // J: Chart zone col 1
        { width: 14 }, // K: Chart zone col 2
        { width: 14 }, // L: Chart zone col 3
        { width: 14 }, // M: Chart zone col 4
    ];

    const calc = calculateRubrikForPt(pt, rubrikSchema);
    const [var1, var2, var3] = calc.variables;

    // -------------------------------------------------------------------------
    // ROW 1: Navigation Back Link + Metadata
    // -------------------------------------------------------------------------
    ws.getRow(1).height = 20;
    ws.mergeCells('A1:D1');
    const backCell = ws.getCell('A1');
    backCell.value = {
        formula: `HYPERLINK("#'Dashboard Nilai'!A1","← Kembali ke Dashboard Nilai")`,
        result: '← Kembali ke Dashboard Nilai',
    };
    backCell.font = { name: FONT_FAMILY, size: 9.5, bold: true, underline: true, color: { argb: 'FF2F6D28' } };
    backCell.alignment = { vertical: 'middle', horizontal: 'left' };

    ws.mergeCells('E1:M1');
    const metaCell = ws.getCell('E1');
    metaCell.value = `Periode ${pt.tahun_periode || '-'}  |  Status: ${pt.status || '-'}  |  Patriot Metric Resume Evaluasi`;
    metaCell.font = { name: FONT_FAMILY, size: 9, italic: true, color: { argb: 'FF6B7280' } };
    metaCell.alignment = { vertical: 'middle', horizontal: 'right' };

    // -------------------------------------------------------------------------
    // ROW 2: Main Title
    // -------------------------------------------------------------------------
    ws.getRow(2).height = 28;
    ws.mergeCells('A2:M2');
    const titleCell = ws.getCell('A2');
    titleCell.value = 'PROFIL PERGURUAN TINGGI';
    titleCell.font = { name: FONT_FAMILY, size: 16, bold: true, color: { argb: 'FF000000' } };
    titleCell.alignment = { vertical: 'middle', horizontal: 'left' };

    // -------------------------------------------------------------------------
    // ROWS 4..9: Left = University Profile | Right = Predikat & Total Skor Card
    // -------------------------------------------------------------------------
    const penilaiText = [
        pt.nama_penilai_1 ? `P1: ${pt.nama_penilai_1} (${Number(pt.nilai_p1 ?? 0).toFixed(2)})` : 'P1: -',
        pt.nama_penilai_2 ? `P2: ${pt.nama_penilai_2} (${Number(pt.nilai_p2 ?? 0).toFixed(2)})` : 'P2: -',
    ].join('  |  ');

    const profileRows = [
        { label: 'NAMA', value: String(pt.nama_institusi || '-').toUpperCase(), fmt: null },
        { label: 'STATUS PENILAIAN', value: pt.status || '-', fmt: 'status' },
        { label: 'JUMLAH DOSEN', value: pt.jml_dosen !== null && pt.jml_dosen !== undefined ? Number(pt.jml_dosen) : '-', fmt: NUM_FMT_INT },
        { label: 'JUMLAH TENAGA KEPENDIDIKAN', value: pt.jml_tendik !== null && pt.jml_tendik !== undefined ? Number(pt.jml_tendik) : '-', fmt: NUM_FMT_INT },
        { label: 'JUMLAH MAHASISWA', value: pt.jml_mahasiswa !== null && pt.jml_mahasiswa !== undefined ? Number(pt.jml_mahasiswa) : '-', fmt: NUM_FMT_INT },
        { label: 'TIM PENILAI', value: penilaiText, fmt: null },
    ];

    profileRows.forEach((pr, idx) => {
        const r = 4 + idx;
        ws.mergeCells(r, 1, r, 2);
        ws.mergeCells(r, 4, r, 8);

        const lbl = ws.getCell(r, 1);
        lbl.value = pr.label;
        lbl.font = { name: FONT_FAMILY, size: 10, bold: true, color: { argb: 'FF111111' } };
        lbl.alignment = { vertical: 'middle', horizontal: 'left' };

        const colon = ws.getCell(r, 3);
        colon.value = ':';
        colon.font = { name: FONT_FAMILY, size: 10, bold: true, color: { argb: 'FF111111' } };
        colon.alignment = { vertical: 'middle', horizontal: 'center' };

        const val = ws.getCell(r, 4);
        val.value = pr.value;
        val.font = { name: FONT_FAMILY, size: 10, bold: idx === 0, color: { argb: 'FF111111' } };
        val.alignment = { vertical: 'middle', horizontal: 'left', wrapText: true };

        if (pr.fmt === 'status') {
            const badge = getStatusBadgeStyle(pr.value);
            val.fill = solidFill(badge.fill);
            val.font = { name: FONT_FAMILY, size: 10, bold: true, color: { argb: badge.font } };
        } else if (pr.fmt && typeof pr.value === 'number') {
            val.numFmt = pr.fmt;
        }

        ws.getRow(r).height = idx === 5 ? estimateRowHeight(penilaiText, 55, 22, 14) : 21;
    });

    // Right side (J4:M9): Predikat Card matching resume.blade.php (#F3ECDB cream + #2F6D28 green)
    ws.mergeCells('J4:K5');
    const predHeader = ws.getCell('J4');
    predHeader.value = 'PREDIKAT PERGURUAN TINGGI';
    predHeader.fill = solidFill('FFF3ECDB');
    predHeader.font = { name: FONT_FAMILY, size: 10, bold: true, color: { argb: 'FF1A1A1A' } };
    predHeader.alignment = { vertical: 'middle', horizontal: 'center', wrapText: true };

    ws.mergeCells('J6:K7');
    const starsCell = ws.getCell('J6');
    starsCell.value = calc.bintang > 0 ? '★'.repeat(calc.bintang) : '-';
    starsCell.fill = solidFill('FFF3ECDB');
    starsCell.font = { name: FONT_FAMILY, size: 18, bold: true, color: { argb: 'FFD97706' } };
    starsCell.alignment = { vertical: 'middle', horizontal: 'center' };

    ws.mergeCells('J8:K8');
    const bintangLblCell = ws.getCell('J8');
    bintangLblCell.value = calc.bintang > 0 ? `BINTANG ${calc.bintang}` : 'BELUM ADA PREDIKAT';
    bintangLblCell.fill = solidFill('FFF3ECDB');
    bintangLblCell.font = { name: FONT_FAMILY, size: 10.5, bold: true, color: { argb: 'FF1A1A1A' } };
    bintangLblCell.alignment = { vertical: 'middle', horizontal: 'center' };

    ws.mergeCells('L4:M5');
    const skorHeader = ws.getCell('L4');
    skorHeader.value = 'Total Skor';
    skorHeader.fill = solidFill('FF2F6D28');
    skorHeader.font = { name: FONT_FAMILY, size: 10.5, bold: true, color: { argb: 'FFFFFFFF' } };
    skorHeader.alignment = { vertical: 'bottom', horizontal: 'center' };

    ws.mergeCells('L6:M8');
    const skorValCell = ws.getCell('L6');
    skorValCell.value = calc.total_skor;
    skorValCell.numFmt = NUM_FMT_DECIMAL;
    skorValCell.fill = solidFill('FF2F6D28');
    skorValCell.font = { name: FONT_FAMILY, size: 22, bold: true, color: { argb: 'FFFFFFFF' } };
    skorValCell.alignment = { vertical: 'middle', horizontal: 'center' };

    ws.mergeCells('J9:M9');
    const skorFoot = ws.getCell('J9');
    const dashFinal = pt.total_skor_akhir !== null && pt.total_skor_akhir !== undefined
        ? Number(pt.total_skor_akhir).toFixed(2)
        : '-';
    const prevYr = pt.nilai_tahun_lalu !== null && pt.nilai_tahun_lalu !== undefined
        ? Number(pt.nilai_tahun_lalu).toFixed(2)
        : '-';
    skorFoot.value = `Skor Final Dashboard: ${dashFinal}  |  Tahun Lalu: ${prevYr}`;
    skorFoot.fill = solidFill('FFF9FAFB');
    skorFoot.font = { name: FONT_FAMILY, size: 8.5, italic: true, color: { argb: 'FF4B5563' } };
    skorFoot.alignment = { vertical: 'middle', horizontal: 'center' };

    applyRangeBorder(ws, 4, 10, 9, 13, {
        top: { style: 'thin', color: { argb: 'FFD4C8B6' } },
        left: { style: 'thin', color: { argb: 'FFD4C8B6' } },
        bottom: { style: 'thin', color: { argb: 'FFD4C8B6' } },
        right: { style: 'thin', color: { argb: 'FFD4C8B6' } },
    });

    // -------------------------------------------------------------------------
    // ROWS 11..22: Left = Variabel Summary Table + Catatan | Right = Pie Chart
    // -------------------------------------------------------------------------
    const summaryStartRow = 11;
    ws.getRow(summaryStartRow).height = 24;
    ws.mergeCells(summaryStartRow, 1, summaryStartRow, 4);
    const sumHeaders = [
        { col: 1, text: 'Ringkasan Capaian Per Variabel' },
        { col: 5, text: 'Skor Saat Ini' },
        { col: 6, text: 'Skor Maks' },
        { col: 7, text: 'Capaian (%)' },
        { col: 8, text: 'Kontribusi (%)' },
    ];
    for (const h of sumHeaders) {
        const c = ws.getCell(summaryStartRow, h.col);
        c.value = h.text;
        c.fill = solidFill('FF2F6D28');
        c.font = { name: FONT_FAMILY, size: 10, bold: true, color: { argb: 'FFFFFFFF' } };
        c.alignment = { vertical: 'middle', horizontal: h.col === 1 ? 'left' : 'center', wrapText: true };
    }
    applyRangeBorder(ws, summaryStartRow, 1, summaryStartRow, 8, THIN_BORDER_DARK);

    calc.variables.forEach((v, i) => {
        const r = summaryStartRow + 1 + i;
        ws.getRow(r).height = 22;
        ws.mergeCells(r, 1, r, 4);

        const nameCell = ws.getCell(r, 1);
        nameCell.value = v.nama;
        nameCell.fill = solidFill(`FF${v.warna_header}`);
        nameCell.font = { name: FONT_FAMILY, size: 9.5, bold: true, color: { argb: 'FF111111' } };
        nameCell.alignment = { vertical: 'middle', horizontal: 'left' };

        const curCell = ws.getCell(r, 5);
        curCell.value = v.skor_saat_ini;
        curCell.numFmt = NUM_FMT_DECIMAL;
        curCell.font = { name: FONT_FAMILY, size: 10, bold: true, color: { argb: 'FF111111' } };
        curCell.alignment = { vertical: 'middle', horizontal: 'right' };

        const maxCell = ws.getCell(r, 6);
        maxCell.value = v.skor_maksimal;
        maxCell.numFmt = NUM_FMT_DECIMAL;
        maxCell.font = { name: FONT_FAMILY, size: 10, color: { argb: 'FF111111' } };
        maxCell.alignment = { vertical: 'middle', horizontal: 'right' };

        const pctCell = ws.getCell(r, 7);
        pctCell.value = v.persentase / 100;
        pctCell.numFmt = '0%';
        pctCell.fill = solidFill(`FF${v.warna_bar_bg}`);
        pctCell.font = { name: FONT_FAMILY, size: 10, bold: true, color: { argb: 'FF111111' } };
        pctCell.alignment = { vertical: 'middle', horizontal: 'center' };

        const contribCell = ws.getCell(r, 8);
        contribCell.value = (v.kontribusi ?? 0) / 100;
        contribCell.numFmt = '0%';
        contribCell.font = { name: FONT_FAMILY, size: 10, color: { argb: 'FF111111' } };
        contribCell.alignment = { vertical: 'middle', horizontal: 'center' };

        applyRangeBorder(ws, r, 1, r, 8, THIN_BORDER_DARK);
    });

    // Total Row (Row 15)
    const totalSummaryRow = summaryStartRow + 4;
    ws.getRow(totalSummaryRow).height = 24;
    ws.mergeCells(totalSummaryRow, 1, totalSummaryRow, 4);

    ws.getCell(totalSummaryRow, 1).value = 'TOTAL SKOR KESELURUHAN';
    ws.getCell(totalSummaryRow, 1).alignment = { vertical: 'middle', horizontal: 'left' };

    ws.getCell(totalSummaryRow, 5).value = {
        formula: `ROUND(SUM(E${summaryStartRow + 1}:E${summaryStartRow + 3}),2)`,
        result: calc.total_skor,
    };
    ws.getCell(totalSummaryRow, 5).numFmt = NUM_FMT_DECIMAL;
    ws.getCell(totalSummaryRow, 5).alignment = { vertical: 'middle', horizontal: 'right' };

    ws.getCell(totalSummaryRow, 6).value = 100;
    ws.getCell(totalSummaryRow, 6).numFmt = NUM_FMT_DECIMAL;
    ws.getCell(totalSummaryRow, 6).alignment = { vertical: 'middle', horizontal: 'right' };

    ws.getCell(totalSummaryRow, 7).value = Math.round(calc.total_skor) / 100;
    ws.getCell(totalSummaryRow, 7).numFmt = '0%';
    ws.getCell(totalSummaryRow, 7).alignment = { vertical: 'middle', horizontal: 'center' };

    ws.getCell(totalSummaryRow, 8).value = calc.total_skor > 0 ? 1 : 0;
    ws.getCell(totalSummaryRow, 8).numFmt = '0%';
    ws.getCell(totalSummaryRow, 8).alignment = { vertical: 'middle', horizontal: 'center' };

    for (let c = 1; c <= 8; c++) {
        const cell = ws.getCell(totalSummaryRow, c);
        cell.fill = solidFill('FFE8F5E9');
        cell.font = { name: FONT_FAMILY, size: 10, bold: true, color: { argb: 'FF1B5E20' } };
    }
    applyRangeBorder(ws, totalSummaryRow, 1, totalSummaryRow, 8, THIN_BORDER_DARK);

    // Catatan Penilaian (Rows 17..23 on Left)
    const catatanBannerRow = 17;
    ws.getRow(catatanBannerRow).height = 24;
    ws.mergeCells(catatanBannerRow, 1, catatanBannerRow, 8);
    const catBanner = ws.getCell(catatanBannerRow, 1);
    catBanner.value = 'CATATAN PENILAIAN';
    catBanner.fill = solidFill('FF2F6D28');
    catBanner.font = { name: FONT_FAMILY, size: 11, bold: true, color: { argb: 'FFFFFFFF' } };
    catBanner.alignment = { vertical: 'middle', horizontal: 'left' };

    const catatanText = pt.catatan_penilaian ? String(pt.catatan_penilaian).trim() : '';
    const catatanEndRow = 23;
    ws.mergeCells(catatanBannerRow + 1, 1, catatanEndRow, 8);
    const catBody = ws.getCell(catatanBannerRow + 1, 1);
    catBody.value = catatanText !== '' ? catatanText : 'catatan belum terisi';
    catBody.font = {
        name: FONT_FAMILY,
        size: 9.5,
        italic: catatanText === '',
        color: { argb: catatanText === '' ? 'FF888888' : 'FF111111' },
    };
    catBody.alignment = { vertical: 'top', horizontal: 'left', wrapText: true };
    applyRangeBorder(ws, catatanBannerRow + 1, 1, catatanEndRow, 8, THIN_BORDER_GRAY);

    // Adjust row heights for catatan rows so long notes fit neatly alongside Pie Chart
    const neededCatatanH = estimateRowHeight(catatanText || 'catatan belum terisi', 88, 120, 14);
    const perCatatanRowH = Math.max(20, Math.ceil(neededCatatanH / (catatanEndRow - catatanBannerRow)));
    for (let r = catatanBannerRow + 1; r <= catatanEndRow; r++) {
        ws.getRow(r).height = perCatatanRowH;
    }

    // Embed Pie Chart on Right (J11..M23)
    if (chartRenderer) {
        const pieBase64 = chartRenderer.renderPieChart(calc.contributions);
        if (pieBase64) {
            const pieImgId = workbook.addImage({ base64: pieBase64, extension: 'png' });
            ws.addImage(pieImgId, {
                tl: { col: 9, row: summaryStartRow - 1 }, // J11 (0-indexed)
                ext: { width: 350, height: 312 },
                editAs: 'oneCell',
            });
        }
    }

    // -------------------------------------------------------------------------
    // RUBRIC VARIABLE SECTIONS (Variabel 1, 2, 3) + RADAR CHARTS
    // -------------------------------------------------------------------------
    let cursorRow = catatanEndRow + 3; // Row 26 (plenty of clearance below Pie Chart!)

    ws.getRow(cursorRow).height = 26;
    ws.mergeCells(cursorRow, 1, cursorRow, 13);
    const secMainTitle = ws.getCell(cursorRow, 1);
    secMainTitle.value = 'SKOR BERDASARKAN VARIABEL & INDIKATOR RUBRIK';
    secMainTitle.font = { name: FONT_FAMILY, size: 14, bold: true, color: { argb: 'FF000000' } };
    secMainTitle.alignment = { vertical: 'middle', horizontal: 'left' };
    cursorRow += 2;

    const varList = [var1, var2, var3].filter(Boolean);
    for (const variable of varList) {
        cursorRow = appendVariableSection({
            workbook,
            ws,
            variable,
            startRow: cursorRow,
            chartRenderer,
        });
        cursorRow += 2; // Clean breathing gap before next variable
    }
}

/**
 * Append one variable rubric table (A..H) + its corresponding Radar Chart (J..M).
 * Returns the next available row below BOTH the table and the radar chart so nothing ever overlaps.
 */
function appendVariableSection({ workbook, ws, variable, startRow, chartRenderer }) {
    let row = startRow;

    // Section Banner (A..M)
    ws.getRow(row).height = 25;
    ws.mergeCells(row, 1, row, 13);
    const banner = ws.getCell(row, 1);
    banner.value = variable.judul_banner || variable.nama;
    banner.fill = solidFill(`FF${variable.warna_banner}`);
    banner.font = { name: FONT_FAMILY, size: 12, bold: true, color: { argb: 'FFFFFFFF' } };
    banner.alignment = { vertical: 'middle', horizontal: 'left' };
    row++;

    // Section Description (A..M)
    ws.getRow(row).height = 22;
    ws.mergeCells(row, 1, row, 13);
    const desc = ws.getCell(row, 1);
    desc.value = variable.deskripsi || '';
    desc.font = { name: FONT_FAMILY, size: 9.5, italic: true, color: { argb: 'FF333333' } };
    desc.alignment = { vertical: 'middle', horizontal: 'left', wrapText: true };
    row++;

    // Table Header (A..H)
    const tableHeaderRow = row;
    ws.getRow(tableHeaderRow).height = 24;
    ws.mergeCells(tableHeaderRow, 2, tableHeaderRow, 4);

    const headers = [
        { col: 1, text: 'No' },
        { col: 2, text: 'Indikator' },
        { col: 5, text: 'Skor R1' },
        { col: 6, text: 'Skor R2' },
        { col: 7, text: 'Rata-Rata (0-5)' },
        { col: 8, text: 'Skor' },
    ];
    for (const h of headers) {
        const c = ws.getCell(tableHeaderRow, h.col);
        c.value = h.text;
        c.fill = solidFill(`FF${variable.warna_header}`);
        c.font = { name: FONT_FAMILY, size: 10, bold: true, color: { argb: 'FF000000' } };
        c.alignment = { vertical: 'middle', horizontal: 'center', wrapText: true };
    }
    applyRangeBorder(ws, tableHeaderRow, 1, tableHeaderRow, 8, THIN_BORDER_DARK);
    row++;

    // Indicator Rows
    const firstIndRow = row;
    let accumulatedTableHeightPt = 24; // header height

    for (const item of variable.indikators) {
        ws.mergeCells(row, 2, row, 4);

        const cNo = ws.getCell(row, 1);
        cNo.value = item.no;
        cNo.font = { name: FONT_FAMILY, size: 9.5, bold: true, color: { argb: 'FF111111' } };
        cNo.alignment = { vertical: 'middle', horizontal: 'center' };

        const cInd = ws.getCell(row, 2);
        cInd.value = item.indikator;
        cInd.font = { name: FONT_FAMILY, size: 9.5, color: { argb: 'FF111111' } };
        cInd.alignment = { vertical: 'middle', horizontal: 'left', wrapText: true };

        const cR1 = ws.getCell(row, 5);
        cR1.value = item.skor_r1 !== null ? item.skor_r1 : '-';
        cR1.font = { name: FONT_FAMILY, size: 9.5, color: { argb: item.skor_r1 !== null ? 'FF111111' : 'FF9CA3AF' } };
        cR1.alignment = { vertical: 'middle', horizontal: 'center' };
        if (item.skor_r1 !== null) cR1.numFmt = NUM_FMT_DECIMAL;

        const cR2 = ws.getCell(row, 6);
        cR2.value = item.skor_r2 !== null ? item.skor_r2 : '-';
        cR2.font = { name: FONT_FAMILY, size: 9.5, color: { argb: item.skor_r2 !== null ? 'FF111111' : 'FF9CA3AF' } };
        cR2.alignment = { vertical: 'middle', horizontal: 'center' };
        if (item.skor_r2 !== null) cR2.numFmt = NUM_FMT_DECIMAL;

        const cAvg = ws.getCell(row, 7);
        cAvg.value = item.avg_raw;
        cAvg.numFmt = NUM_FMT_DECIMAL;
        cAvg.font = { name: FONT_FAMILY, size: 9.5, color: { argb: 'FF111111' } };
        cAvg.alignment = { vertical: 'middle', horizontal: 'center' };

        const cSkor = ws.getCell(row, 8);
        cSkor.value = item.skor;
        cSkor.numFmt = NUM_FMT_DECIMAL;
        cSkor.font = { name: FONT_FAMILY, size: 10, bold: true, color: { argb: 'FF111111' } };
        cSkor.alignment = { vertical: 'middle', horizontal: 'center' };

        applyRangeBorder(ws, row, 1, row, 8, THIN_BORDER_DARK);

        const rowH = estimateRowHeight(item.indikator, 52, 22, 13.5);
        ws.getRow(row).height = rowH;
        accumulatedTableHeightPt += rowH;
        row++;
    }

    const lastIndRow = row - 1;

    // Subtotal Row
    ws.getRow(row).height = 24;
    ws.mergeCells(row, 1, row, 6);
    const subLabel = ws.getCell(row, 1);
    subLabel.value = `Subtotal ${variable.judul_pendek} (Skor Maksimal: ${variable.skor_maksimal}, Capaian: ${variable.persentase}%)`;
    subLabel.alignment = { vertical: 'middle', horizontal: 'right' };

    const subAvg = ws.getCell(row, 7);
    subAvg.value = {
        formula: `ROUND(AVERAGE(G${firstIndRow}:G${lastIndRow}),2)`,
        result: variable.indikators.length > 0
            ? Math.round((variable.radar_scores.reduce((a, b) => a + b, 0) / variable.indikators.length) * 100) / 100
            : 0,
    };
    subAvg.numFmt = NUM_FMT_DECIMAL;
    subAvg.alignment = { vertical: 'middle', horizontal: 'center' };

    const subSkor = ws.getCell(row, 8);
    subSkor.value = variable.skor_saat_ini;
    subSkor.numFmt = NUM_FMT_DECIMAL;
    subSkor.alignment = { vertical: 'middle', horizontal: 'center' };

    for (let c = 1; c <= 8; c++) {
        const cell = ws.getCell(row, c);
        cell.fill = solidFill(`FF${variable.warna_header}`);
        cell.font = { name: FONT_FAMILY, size: 10, bold: true, color: { argb: 'FF000000' } };
    }
    applyRangeBorder(ws, row, 1, row, 8, THIN_BORDER_DARK);
    accumulatedTableHeightPt += 24;
    row++;

    // Embed Radar Chart on Right (J{tableHeaderRow}..M)
    const chartPixelHeight = 322;
    const chartHeightPt = Math.ceil(chartPixelHeight * 0.75) + 12; // ~254pt

    if (chartRenderer) {
        const palette = RADAR_PALETTES[variable.key] || RADAR_PALETTES.variabel_1;
        const radarBase64 = chartRenderer.renderRadarChart({
            title: variable.judul_pendek,
            labels: variable.indikators.map((x) => x.no),
            values: variable.radar_scores,
            ...palette,
        });

        if (radarBase64) {
            const imgId = workbook.addImage({ base64: radarBase64, extension: 'png' });
            ws.addImage(imgId, {
                tl: { col: 9, row: tableHeaderRow - 1 }, // J{tableHeaderRow} (0-indexed)
                ext: { width: 350, height: chartPixelHeight },
                editAs: 'oneCell',
            });
        }
    }

    // If table is shorter than the Radar Chart (e.g., Variabel 1 with only 5 rows), pad rows cleanly so next section never overlaps chart
    let currentPt = accumulatedTableHeightPt;
    while (currentPt < chartHeightPt) {
        ws.getRow(row).height = 20;
        currentPt += 20;
        row++;
    }

    return row;
}
