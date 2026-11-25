/**
 * Lightweight, zero-dependency HTML5 Canvas chart renderer for Excel embedding.
 * Reuses a single offscreen <canvas> across all PT sheets so browser memory stays low.
 * Visual style matches resources/views/pdf/resume.blade.php (Pie + 3 Radar charts).
 */

export class CanvasChartRenderer {
    constructor() {
        this.canvas = null;
        this.ctx = null;
    }

    _ensureCanvas(width, height) {
        if (!this.canvas) {
            this.canvas = document.createElement('canvas');
            this.ctx = this.canvas.getContext('2d');
        }
        if (this.canvas.width !== width || this.canvas.height !== height) {
            this.canvas.width = width;
            this.canvas.height = height;
        }
        const ctx = this.ctx;
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.clearRect(0, 0, width, height);
        return ctx;
    }

    _toBase64Png() {
        const dataUrl = this.canvas.toDataURL('image/png');
        return dataUrl.replace(/^data:image\/png;base64,/, '');
    }

    /**
     * Render Pie / Donut Chart: Kontribusi skor tiap variabel pada total skor.
     */
    renderPieChart({ pct1 = 0, pct2 = 0, pct3 = 0 }) {
        const scale = 2; // Crisp HiDPI
        const w = 380;
        const h = 340;
        const ctx = this._ensureCanvas(w * scale, h * scale);
        ctx.scale(scale, scale);

        // Background card
        ctx.fillStyle = '#FFFFFF';
        ctx.fillRect(0, 0, w, h);
        ctx.strokeStyle = '#E5E7EB';
        ctx.lineWidth = 1;
        ctx.strokeRect(0.5, 0.5, w - 1, h - 1);

        // Title
        ctx.fillStyle = '#1A1A1A';
        ctx.font = 'bold 13px Arial, Helvetica, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Kontribusi Skor Tiap Variabel', w / 2, 24);
        ctx.font = '11px Arial, Helvetica, sans-serif';
        ctx.fillStyle = '#4B5563';
        ctx.fillText('terhadap Total Skor Perguruan Tinggi', w / 2, 40);

        const cx = w / 2;
        const cy = 152;
        const r = 90;

        const slices = [
            { label: 'Patriotisme Kebijakan', value: Math.max(0, Number(pct1) || 0), color: '#2F6D28' },
            { label: 'Patriotisme Kelembagaan', value: Math.max(0, Number(pct2) || 0), color: '#DF692A' },
            { label: 'Patriotisme Mahasiswa', value: Math.max(0, Number(pct3) || 0), color: '#F5A623' },
        ];

        const total = slices.reduce((acc, s) => acc + s.value, 0);

        if (total <= 0) {
            // Empty state ring
            ctx.beginPath();
            ctx.arc(cx, cy, r, 0, Math.PI * 2);
            ctx.fillStyle = '#F3F4F6';
            ctx.fill();
            ctx.lineWidth = 2;
            ctx.strokeStyle = '#D1D5DB';
            ctx.stroke();

            ctx.fillStyle = '#6B7280';
            ctx.font = 'bold 12px Arial, Helvetica, sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText('Belum Ada Skor', cx, cy - 6);
            ctx.font = '11px Arial, Helvetica, sans-serif';
            ctx.fillText('(0,00)', cx, cy + 12);
        } else {
            let startAngle = -Math.PI / 2;

            for (const slice of slices) {
                if (slice.value <= 0) continue;
                const sliceAngle = (slice.value / total) * Math.PI * 2;
                const endAngle = startAngle + sliceAngle;

                ctx.beginPath();
                ctx.moveTo(cx, cy);
                ctx.arc(cx, cy, r, startAngle, endAngle);
                ctx.closePath();
                ctx.fillStyle = slice.color;
                ctx.fill();

                ctx.lineWidth = 2.2;
                ctx.strokeStyle = '#FFFFFF';
                ctx.stroke();

                // Label percentage inside slice when slice is wide enough (>= 8%)
                if (slice.value >= 8) {
                    const midAngle = startAngle + sliceAngle / 2;
                    const lx = cx + (r * 0.62) * Math.cos(midAngle);
                    const ly = cy + (r * 0.62) * Math.sin(midAngle);

                    ctx.fillStyle = slice.color === '#F5A623' ? '#111111' : '#FFFFFF';
                    ctx.font = 'bold 12px Arial, Helvetica, sans-serif';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(`${slice.value}%`, lx, ly);
                }

                startAngle = endAngle;
            }

            // Crisp outer border
            ctx.beginPath();
            ctx.arc(cx, cy, r, 0, Math.PI * 2);
            ctx.lineWidth = 1.5;
            ctx.strokeStyle = '#E5E7EB';
            ctx.stroke();
        }

        // Bottom Legend (3 clean rows)
        ctx.textBaseline = 'middle';
        const legendStartY = 266;
        const legendRowH = 21;
        const legendLeft = 42;

        slices.forEach((slice, idx) => {
            const y = legendStartY + idx * legendRowH;

            // Color swatch
            ctx.fillStyle = slice.color;
            ctx.fillRect(legendLeft, y - 6, 12, 12);

            // Label
            ctx.fillStyle = '#1F2937';
            ctx.font = '11.5px Arial, Helvetica, sans-serif';
            ctx.textAlign = 'left';
            ctx.fillText(slice.label, legendLeft + 18, y);

            // Percentage on right
            ctx.fillStyle = '#111827';
            ctx.font = 'bold 11.5px Arial, Helvetica, sans-serif';
            ctx.textAlign = 'right';
            ctx.fillText(`${slice.value}%`, w - 42, y);
        });

        return this._toBase64Png();
    }

    /**
     * Render Radar Chart (5 axes for Kebijakan, 20 axes for Kelembagaan, 15 axes for Mahasiswa).
     * Mirrors ResumeReportService's concentric 0..5 spider geometry and color palette.
     */
    renderRadarChart({
        title,
        labels,
        values,
        strokeColor = '#2D6D25',
        fillColor = 'rgba(45, 109, 37, 0.18)',
        gridColor = '#CAD8C8',
        centerDotColor = '#7A8F77',
    }) {
        const scale = 2; // Crisp HiDPI
        const w = 380;
        const h = 350;
        const ctx = this._ensureCanvas(w * scale, h * scale);
        ctx.scale(scale, scale);

        // Background card
        ctx.fillStyle = '#FFFFFF';
        ctx.fillRect(0, 0, w, h);
        ctx.strokeStyle = '#E5E7EB';
        ctx.lineWidth = 1;
        ctx.strokeRect(0.5, 0.5, w - 1, h - 1);

        // Title
        ctx.fillStyle = '#1A1A1A';
        ctx.font = 'bold 14px Arial, Helvetica, sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(title, w / 2, 24);

        const num = labels.length;
        const maxVal = 5;
        const cx = w / 2;
        const cy = 190;
        const radius = num >= 18 ? 105 : num >= 12 ? 110 : 114;
        const angleStep = (Math.PI * 2) / num;
        const startRad = -Math.PI / 2;
        const levels = [1, 2, 3, 4, 5];

        // 1. Concentric polygon grid (levels 1..5)
        ctx.strokeStyle = gridColor;
        ctx.lineWidth = 1.1;
        for (const lvl of levels) {
            const lvlR = (lvl / maxVal) * radius;
            ctx.beginPath();
            for (let i = 0; i < num; i++) {
                const a = startRad + i * angleStep;
                const x = cx + lvlR * Math.cos(a);
                const y = cy + lvlR * Math.sin(a);
                if (i === 0) ctx.moveTo(x, y);
                else ctx.lineTo(x, y);
            }
            ctx.closePath();
            ctx.stroke();
        }

        // 2. Spokes
        ctx.strokeStyle = gridColor;
        ctx.lineWidth = 0.9;
        for (let i = 0; i < num; i++) {
            const a = startRad + i * angleStep;
            ctx.beginPath();
            ctx.moveTo(cx, cy);
            ctx.lineTo(cx + radius * Math.cos(a), cy + radius * Math.sin(a));
            ctx.stroke();
        }

        // 3. Center dot (= 0)
        ctx.beginPath();
        ctx.arc(cx, cy, 2.6, 0, Math.PI * 2);
        ctx.fillStyle = centerDotColor;
        ctx.fill();

        // 4. Scale labels (0..5 along top vertical axis)
        ctx.fillStyle = '#555555';
        ctx.font = 'bold 9.5px Arial, Helvetica, sans-serif';
        ctx.textAlign = 'right';
        ctx.textBaseline = 'middle';
        ctx.fillText('0', cx - 6, cy);
        for (const lvl of levels) {
            const lvlR = (lvl / maxVal) * radius;
            ctx.fillText(String(lvl), cx - 6, cy - lvlR);
        }

        // 5. Data polygon
        const points = [];
        for (let i = 0; i < num; i++) {
            const raw = Math.min(maxVal, Math.max(0, Number(values[i] || 0)));
            const dist = (raw / maxVal) * radius;
            const a = startRad + i * angleStep;
            points.push({
                x: cx + dist * Math.cos(a),
                y: cy + dist * Math.sin(a),
            });
        }

        if (points.length > 0) {
            ctx.beginPath();
            points.forEach((pt, idx) => {
                if (idx === 0) ctx.moveTo(pt.x, pt.y);
                else ctx.lineTo(pt.x, pt.y);
            });
            ctx.closePath();
            ctx.fillStyle = fillColor;
            ctx.fill();
            ctx.lineWidth = 2.4;
            ctx.strokeStyle = strokeColor;
            ctx.stroke();

            // Vertex dots
            const dotR = num >= 15 ? 3.2 : 4.2;
            ctx.fillStyle = strokeColor;
            for (const pt of points) {
                ctx.beginPath();
                ctx.arc(pt.x, pt.y, dotR, 0, Math.PI * 2);
                ctx.fill();
            }
        }

        // 6. Axis labels
        const labelOffset = num >= 18 ? 16 : num >= 12 ? 17 : 20;
        const fontSize = num >= 18 ? 10 : num >= 12 ? 10.5 : 12;
        ctx.fillStyle = '#111111';
        ctx.font = `bold ${fontSize}px Arial, Helvetica, sans-serif`;
        ctx.textBaseline = 'middle';

        for (let i = 0; i < num; i++) {
            const a = startRad + i * angleStep;
            const cosA = Math.cos(a);
            const sinA = Math.sin(a);
            const lx = cx + (radius + labelOffset) * cosA;
            const ly = cy + (radius + labelOffset) * sinA;

            if (Math.abs(cosA) < 0.18) {
                ctx.textAlign = 'center';
            } else if (cosA > 0) {
                ctx.textAlign = 'left';
            } else {
                ctx.textAlign = 'right';
            }

            ctx.fillText(labels[i], lx, ly);
        }

        return this._toBase64Png();
    }

    dispose() {
        if (this.canvas) {
            this.canvas.width = 0;
            this.canvas.height = 0;
            this.canvas = null;
            this.ctx = null;
        }
    }
}
