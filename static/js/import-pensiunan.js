            document.getElementById('form-import').addEventListener('submit', function(e) {
                const overlay = document.getElementById('overlay-import');
                const progressBar = document.getElementById('overlay-progress-bar');
                const progressText = document.getElementById('overlay-progress-text');

                overlay.classList.remove('hidden');

                const pesan = [
                    'Membaca file Excel...',
                    'Memvalidasi data...',
                    'Mengenkripsi data pensiunan...',
                    'Menyimpan ke database...',
                    'Data dalam jumlah besar bisa memakan waktu lebih lama, mohon tunggu...',
                ];

                let progress = 5;
                let pesanIndex = 0;

                const interval = setInterval(() => {
                    if (progress < 88) {
                        progress += (88 - progress) * 0.08;
                        progressBar.style.width = progress + '%';
                    }
                    if (pesanIndex < pesan.length - 1 && progress > (pesanIndex + 1) * 18) {
                        pesanIndex++;
                        progressText.textContent = pesan[pesanIndex];
                    }
                }, 400);
            });
            