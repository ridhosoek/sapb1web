<section class="operational-panel mb-3 border-danger">
    <div class="panel-body text-center py-5">
        <i class="fa-solid fa-cloud-arrow-down text-danger fs-2 mb-3"></i>
        <h2 class="h5">Data belum dapat dimuat</h2>
        <p class="text-secondary mb-3">{{ $message ?? 'Koneksi ke sumber data sedang terganggu. Silakan refresh halaman ini beberapa saat lagi.' }}</p>
        <button class="btn btn-primary" type="button" onclick="window.location.reload()"><i class="fa-solid fa-rotate me-1"></i> Refresh Halaman</button>
    </div>
</section>
