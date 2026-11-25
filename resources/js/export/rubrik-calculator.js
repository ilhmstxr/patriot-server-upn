/**
 * Pure calculation utilities for Daftar Nilai Excel & CSV export.
 * Mirrors App\Services\ResumeReportService so Excel output matches resources/views/pdf/resume.blade.php.
 */

export function round2(val) {
    return Math.round((Number(val) + Number.EPSILON) * 100) / 100;
}

export function clamp(val, min, max) {
    return Math.min(max, Math.max(min, val));
}

/**
 * Resolve star rating (0 = none/unverified, 1..5 = Bintang 1..5).
 */
export function resolveBintang(score, status = null) {
    if (status && String(status).toUpperCase() === 'UNVERIFIED') {
        return 0;
    }
    if (score === null || score === undefined || score === '') {
        return 0;
    }
    const num = Number(score);
    if (!Number.isFinite(num) || num <= 0) {
        return 0;
    }
    if (num >= 85) return 5;
    if (num >= 70) return 4;
    if (num >= 50) return 3;
    if (num >= 30) return 2;
    return 1;
}

export function formatBintangLabel(bintang) {
    const n = Number(bintang || 0);
    return n > 0 ? `Bintang ${n}` : '-';
}

/**
 * Determine Sheet 1 (Dashboard Nilai) row background tone per Requirement #2:
 * - status PT === UNVERIFIED -> 'red'
 * - skor akhir penilaian PT === 0 -> 'orange'
 * - kosong / tidak ada (null/undefined/'') atau skor > 0 -> 'white'
 */
export function resolveDashboardRowTone(pt) {
    const status = String(pt?.status ?? '').trim().toUpperCase();
    if (status === 'UNVERIFIED' || pt?.is_unverified === true) {
        return 'red';
    }

    const finalScore = pt?.total_skor_akhir;
    if (finalScore === null || finalScore === undefined || finalScore === '') {
        return 'white';
    }

    const numericScore = Number(finalScore);
    if (Number.isFinite(numericScore) && numericScore === 0) {
        return 'orange';
    }

    return 'white';
}

/**
 * Compute full per-rubric breakdown for one PT (mirrors ResumeReportService).
 */
export function calculateRubrikForPt(pt, rubrikSchema) {
    const scoreMap = pt?.scores && typeof pt.scores === 'object' ? pt.scores : {};
    const variables = [];
    let totalSkor = 0;

    for (const varSchema of rubrikSchema) {
        const indikators = varSchema.indikators || [];
        const count = indikators.length;
        const skorMaksimal = Number(varSchema.skor_maksimal || 0);
        const bobotPerIndikator = count > 0 ? skorMaksimal / count : 0;

        let sumRaw = 0;
        const rows = [];
        const radarScores = [];

        for (const ind of indikators) {
            const pair = scoreMap[ind.kode];
            const r1 = Array.isArray(pair) && pair[0] !== null && pair[0] !== undefined
                ? clamp(Number(pair[0]), 0, 5)
                : null;
            const r2 = Array.isArray(pair) && pair[1] !== null && pair[1] !== undefined
                ? clamp(Number(pair[1]), 0, 5)
                : null;

            // Rumus resmi sesuai ResumeReportService: (R1 + R2) / 2 (null dihitung 0)
            const avgRaw = round2(((r1 ?? 0) + (r2 ?? 0)) / 2);
            const skor = round2((avgRaw / 5) * bobotPerIndikator);

            sumRaw += avgRaw;
            radarScores.push(avgRaw);

            rows.push({
                kode: ind.kode,
                no: ind.no,
                indikator: ind.indikator,
                skor_r1: r1,
                skor_r2: r2,
                avg_raw: avgRaw,
                skor,
                skor_maks_indikator: round2(bobotPerIndikator),
            });
        }

        const varTotal = count > 0
            ? Math.min(skorMaksimal, round2((sumRaw / (count * 5)) * skorMaksimal))
            : 0;
        const persentase = skorMaksimal > 0
            ? Math.min(100, Math.round((varTotal / skorMaksimal) * 100))
            : 0;

        totalSkor += varTotal;

        variables.push({
            ...varSchema,
            skor_saat_ini: varTotal,
            skor_maksimal: skorMaksimal,
            persentase,
            indikators: rows,
            radar_scores: radarScores,
        });
    }

    totalSkor = Math.min(100, round2(totalSkor));

    // Kontribusi persen tiap variabel terhadap total skor (untuk Pie Chart)
    let contrib1 = 0;
    let contrib2 = 0;
    let contrib3 = 0;
    if (totalSkor > 0 && variables.length >= 3) {
        contrib1 = Math.round((variables[0].skor_saat_ini / totalSkor) * 100);
        contrib2 = Math.round((variables[1].skor_saat_ini / totalSkor) * 100);
        contrib3 = Math.max(0, 100 - contrib1 - contrib2);
    }

    if (variables[0]) variables[0].kontribusi = contrib1;
    if (variables[1]) variables[1].kontribusi = contrib2;
    if (variables[2]) variables[2].kontribusi = contrib3;

    const bintangResume = resolveBintang(totalSkor, pt?.status);

    return {
        variables,
        total_skor: totalSkor,
        bintang: bintangResume,
        contributions: {
            pct1: contrib1,
            pct2: contrib2,
            pct3: contrib3,
        },
    };
}

/**
 * Sanitize and guarantee a valid, unique Excel worksheet name (<= 31 chars, no forbidden chars).
 */
export function makeUniqueSheetName(index, rawName, usedLowerNames) {
    const cleaned = String(rawName || `PT ${index}`)
        .replace(/[\\/?*[\]:"']/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

    const prefix = `${index}. `;
    const maxBaseLen = Math.max(1, 31 - prefix.length);

    let candidate = (prefix + cleaned.slice(0, maxBaseLen)).trim();
    if (!candidate) {
        candidate = `PT ${index}`;
    }

    // Hindari tabrakan case-insensitive (termasuk dengan "Dashboard Nilai")
    if (!usedLowerNames.has(candidate.toLowerCase())) {
        usedLowerNames.add(candidate.toLowerCase());
        return candidate;
    }

    let suffix = 2;
    while (true) {
        const tag = ` (${suffix})`;
        const cutLen = Math.max(1, 31 - prefix.length - tag.length);
        const alt = (prefix + cleaned.slice(0, cutLen)).trim() + tag;
        if (!usedLowerNames.has(alt.toLowerCase())) {
            usedLowerNames.add(alt.toLowerCase());
            return alt;
        }
        suffix++;
    }
}

/**
 * Format Indonesian decimal for CSV (e.g., 51.1 -> "51,10" or numeric "51.10").
 * Uses standard decimal dot so Excel / pandas / BI tools parse cleanly as numbers,
 * while preserving empty string for null values.
 */
function escapeCsvCell(value) {
    if (value === null || value === undefined) {
        return '';
    }
    const str = String(value);
    if (/[",\r\n;]/.test(str)) {
        return `"${str.replace(/"/g, '""')}"`;
    }
    return str;
}

function formatNumericForCsv(val) {
    if (val === null || val === undefined || val === '') {
        return '';
    }
    const num = Number(val);
    return Number.isFinite(num) ? num.toFixed(2) : '';
}

/**
 * Build UTF-8 BOM RFC-4180 CSV for Sheet 1 (Dashboard Nilai).
 */
export function buildDashboardCsv(items) {
    const headers = [
        'No',
        'Nama Perguruan Tinggi',
        'Status',
        'Rata-Rata Nilai',
        'Perolehan Bintang',
        'Nama Penilai 1',
        'Nilai P1',
        'Nama Penilai 2',
        'Nilai P2',
        'Nilai Tahun Lalu',
        'Perolehan Bintang Tahun Lalu',
    ];

    const lines = [headers.map(escapeCsvCell).join(',')];

    items.forEach((pt, idx) => {
        const displayScore = pt.total_skor_akhir !== null && pt.total_skor_akhir !== undefined
            ? pt.total_skor_akhir
            : pt.nilai_rata_rata;
        const bintang = pt.bintang ?? resolveBintang(displayScore, pt.status);

        const row = [
            idx + 1,
            pt.nama_institusi || '-',
            pt.status || '-',
            formatNumericForCsv(displayScore),
            formatBintangLabel(bintang),
            pt.nama_penilai_1 || '',
            formatNumericForCsv(pt.nilai_p1),
            pt.nama_penilai_2 || '',
            formatNumericForCsv(pt.nilai_p2),
            formatNumericForCsv(pt.nilai_tahun_lalu),
            formatBintangLabel(pt.bintang_tahun_lalu),
        ];

        lines.push(row.map(escapeCsvCell).join(','));
    });

    // UTF-8 BOM agar Microsoft Excel langsung membaca karakter Indonesia dengan benar
    return '\uFEFF' + lines.join('\r\n');
}
