</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

<script src="<?= BASE_URL ?>/assets/js/stagia.js?v=<?= time() ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/communication-notifications-global.js?v=<?= filemtime(__DIR__.'/../assets/js/communication-notifications-global.js') ?>"></script>

<script>
const sidebarToggle=document.getElementById('sidebarToggle');

if(sidebarToggle){
    sidebarToggle.onclick=()=>{
        document.body.classList.toggle('sidebar-collapsed');
    };
}
</script>

</body>
</html>
