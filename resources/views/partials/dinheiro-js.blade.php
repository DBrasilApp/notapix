{{-- Campos de valor (data-dinheiro): os números entram da direita para a esquerda (digite 1890 para 18,90) --}}
@once
    <script>
        (() => {
            const formatar = (el) => {
                const centavos = el.value.replace(/\D/g, '').replace(/^0+/, '').slice(0, 13);
                el.value = centavos ? (Number(centavos) / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '';
            };
            window.ligarDinheiro = (raiz = document) => raiz.querySelectorAll('[data-dinheiro]:not([data-ligado])').forEach((el) => {
                el.dataset.ligado = '1';
                el.addEventListener('input', () => { formatar(el); el.dispatchEvent(new Event('valor')); });
                el.addEventListener('focus', () => setTimeout(() => el.setSelectionRange(el.value.length, el.value.length)));
            });
            window.lerDinheiro = (el) => Number(String(el?.value || '').replace(/\./g, '').replace(',', '.')) || 0;
            window.mostrarDinheiro = (n) => (Math.round(n * 100) / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.addEventListener('DOMContentLoaded', () => window.ligarDinheiro());
        })();
    </script>
@endonce
