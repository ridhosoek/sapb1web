<div class="table-responsive">
    <table class="table table-sm table-operational align-middle mb-0">
        <thead><tr><th>#</th><th>Item</th><th class="text-end">Qty</th><th>Gudang</th></tr></thead>
        <tbody>
        @foreach($lines as $index => $line)
            <tr><td>{{ $index + 1 }}</td><td class="mono">{{ $line['ItemCode'] }}</td><td class="text-end mono">{{ $line['Quantity'] }}</td><td class="mono">{{ $line['WarehouseCode'] }}</td></tr>
        @endforeach
        </tbody>
    </table>
</div>
