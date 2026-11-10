</main><script>
document.querySelectorAll('[data-confirm]').forEach(el=>el.addEventListener('click',ev=>{if(!confirm(el.dataset.confirm))ev.preventDefault()}));
</script></body></html>