<script>
    (() => {
        const tel = document.getElementById('telefone');
        if (!tel) return;

        const formatar = () => {
            const n = tel.value.replace(/\D/g, '').slice(0, 11);
            if (n.length <= 2) { tel.value = n; return; }
            const ddd = n.slice(0, 2), resto = n.slice(2);
            const corte = n.length > 10 ? 5 : 4;
            tel.value = resto.length > corte
                ? `(${ddd}) ${resto.slice(0, corte)}-${resto.slice(corte)}`
                : `(${ddd}) ${resto}`;
        };

        tel.addEventListener('input', formatar);
        formatar();
    })();
</script>
