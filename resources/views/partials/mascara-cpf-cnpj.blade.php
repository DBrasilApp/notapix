{{-- Máscara que vira CPF (até 11 números) ou CNPJ (12 a 14 números) enquanto a pessoa digita --}}
@once
    <script>
        window.mascaraCpfCnpj = (valor) => {
            const n = String(valor || '').replace(/\D/g, '').slice(0, 14);
            if (n.length <= 11) {
                return n.replace(/^(\d{3})(\d)/, '$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d{1,2})$/, '.$1-$2');
            }
            return n.replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
                .replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d)/, '$1-$2');
        };
    </script>
@endonce
