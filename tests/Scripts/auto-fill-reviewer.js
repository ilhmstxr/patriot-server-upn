(() => {

const pdfAssessmentData = {
    "A.1": {
        score: 2,
        note: "bukti kebijakan belum disertakan hanya bukti kegiatan yang sudah diimplementasikan"
    },
    "A.2": {
        score: 5,
        note: "Adanya dokumen kebijakan tentang pencegahan dan penanganan kekerasan; sudah dibentuknya unit dan satgas; sosialisasi; dan kanal pelaporan/bilik aduan,"
    },
    "A.3": {
        score: 2,
        note: "Belum ada bukti pelaksanaan sosialisasi Pencegahan dan Pemberantasan Penyalahgunaan dan Peredaran Gelap Narkotika dan Prekursor Narkotika."
    },
    "A.4": {
        score: 3,
        note: "belum terlihat sarana dan prasarana yang mendukung serta belum adanya training berkala"
    },
    "A.5": {
        score: 5,
        note: "Ada kebijakan dan diterapkan oleh seluruh sivitas akademika (dosen, tendik, dan mahasiswa)"
    },
    "B.1": {
        score: 3,
        note: "lama beroperasi ≤ 24 bulan"
    },
    "B.2": {
        score: 5,
        note: "Jumlah implementasi penyelenggaraan MKWK > 8 SKS"
    },
    "B.3": {
        score: 5,
        note: "Jumlah mata kuliah wajib institusi berkarakter bela negara > 4 mata kuliah"
    },
    "B.4": {
        score: 0,
        note: "bukti bukan tentang kelompok riset"
    },
    "B.5": {
        score: null,
        note: "Tidak ada bukti dokumentasi"
    },
    "B.6": {
        score: 5,
        note: "tidak ada ST dan atau laporan kegiatan"
    },
    "B.7": {
        score: 5,
        note: "Jumlah kolaborasi dengan UMKM atau pelaku usaha lokal untuk mendukung program tridharma > 4%"
    },
    "B.8": {
        score: 0,
        note: "Tidak ada keikutsertaan dosen dan atau tenaga kependidikan dalam pelatihan bela negara"
    },
    "B.9": {
        score: 4,
        note: "Lama Beroperasi Pusat konseling bagi sivitas akademik ≤ 36 bulan"
    },
    "B.10": {
        score: 0,
        note: "Jumlah pegawai institusi sebagai anggota komponen cadangan tidak ada"
    },
    "B.11": {
        score: 4,
        note: "Upacara 17 Agustus, Hari Pendidikan Nasional + 2 Hari Besar Nasional lainnya"
    },
    "B.12": {
        score: 5,
        note: "Jumlah kegiatan sosialisasi/seminar/workshop tentang langkah-langkah menjaga kebersihan, keamanan dan pengelolaan lingkungan, serta anti-radikalisme, seperti pelatihan deteksi dini risiko keamanan atau mitigasi bencana >12"
    },
    "B.13": {
        score: 4,
        note: "Jumlah penyelenggaraan kompetisi yang bertema nilai-nilai bela negara ≤ 100"
    },
    "B.14": {
        score: 2,
        note: "Jumlah penghargaan atau insentif atas prestasi sivitas akademika dari perguruan tinggi 12 peserta"
    },
    "B.15": {
        score: 0,
        note: "tidak jelas jumlah fasilitas akomodasi yang layak untuk peserta didik penyandang disabilitas di lingkungan pendidikan tinggi"
    },
    "B.16": {
        score: 5,
        note: "Jumlah ragam dan variasi media kampanye nilai-nilai cinta tanah air >4 variasi"
    },
    "B.17": {
        score: 5,
        note: "belum ada daftar inventarisnya"
    },
    "B.18": {
        score: 2,
        note: "Jumlah ruang (sekretariat) untuk organisasi mahasiswa seperti Himpunan Mahasiswa dan UKM < 50%"
    },
    "B.19": {
        score: 3,
        note: "belum ada Surat Keputusan (SK)"
    },
    "B.20": {
        score: 3,
        note: "tidak ada data identitas agama mahasiswa, laporan, dan dokumentasi"
    }
};


    const getAlpineData = () => {
        const elements = document.querySelectorAll('[x-data]');
        for (const el of elements) {
            let data = null;
            if (el.__x && el.__x.$data) {
                data = el.__x.$data;
            } else if (window.Alpine && typeof window.Alpine.$data === 'function') {
                data = window.Alpine.$data(el);
            } else if (el._x_dataStack) {
                data = el._x_dataStack[0];
            }

            if (data && data.rubrikData !== undefined && data.reviewerScores !== undefined) {
                return data;
            }
        }
        return null;
    };

    const run = async () => {
        const data = getAlpineData();
        if (!data) {
            console.error("%c[Error] Gagal menemukan element Alpine.js yang mengelola penilaian reviewer.", "color: #ff3333; font-weight: bold;");
            return;
        }

        if (data.loading) {
            while (data.loading) {
                await new Promise(resolve => setTimeout(resolve, 500));
            }
        }

        const questions = [];
        if (Array.isArray(data.rubrikData)) {
            data.rubrikData.forEach(kategori => {
                if (Array.isArray(kategori.pertanyaan)) {
                    kategori.pertanyaan.forEach(q => questions.push(q));
                }
            });
        }

        if (!questions.length) {
            console.warn("%c[Warning] Tidak ada pertanyaan/indikator yang ditemukan.", "color: #ffaa00; font-weight: bold;");
            return;
        }

        data.reviewerNotes = data.reviewerNotes || {};

        for (let i = 0; i < questions.length; i++) {
            const q = questions[i];
            const kode = (q.kode_pertanyaan || "").trim();
            const mapped = pdfAssessmentData[kode];

            if (!mapped) {
                console.warn(`%c[${i + 1}/${questions.length}] "${kode}" (ID: ${q.id}) tidak ada di mapping PDF, dilewati.`, "color: #d97706;");
                continue;
            }

            const finalNote = mapped.note.length >= 20
                ? mapped.note
                : `${mapped.note} (catatan verifikasi penilai).`;

            data.reviewerScores[q.id] = mapped.score;
            data.reviewerNotes[q.id] = finalNote;

            console.log(`%c[${i + 1}/${questions.length}] ${kode} (ID: ${q.id}) -> Skor: ${mapped.score}, Catatan: "${finalNote}"`, "color: #0284c7;");

            if (typeof data.saveQuestionScore === "function") {
                try {
                    await data.saveQuestionScore.call(data, q.id);
                    console.log(`   -> %cBerhasil tersimpan ke server.`, "color: #16a34a;");
                } catch (err) {
                    console.error(`   -> %cGagal menyimpan skor untuk ${kode}:`, "color: #dc2626;", err);
                }
            }

            await new Promise(resolve => setTimeout(resolve, 200));
        }

        console.log("%c[Success] Selesai mengisi sesuai PDF.", "color: #1b5e20; font-weight: bold; font-size: 14px;");
    };

    run();
})();