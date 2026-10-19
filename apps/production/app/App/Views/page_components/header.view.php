<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? $pageTitle : "Default Title"?></title>

    <link rel="preconnect" href="https://fonts.gstatic.com">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?=ROOT?>/assets/css/bootstrap.css">
    <link rel="stylesheet" href="<?=ROOT?>/assets/vendors/perfect-scrollbar/perfect-scrollbar.css">
    <link rel="stylesheet" href="<?=ROOT?>/assets/vendors/bootstrap-icons/bootstrap-icons.css">
    <link rel="stylesheet" href="<?=ROOT?>/assets/css/app.css">
    <link rel="shortcut icon" href="<?=ROOT?>/assets/images/favicon.svg" type="image/x-icon">
    <link rel="stylesheet" href="<?=ROOT?>/assets/css/snackbarStyle.css">
    <link rel="stylesheet" href="<?=ROOT?>/assets/vendors/dripicons/webfont.css">
    <link rel="stylesheet" href="<?=ROOT?>/assets/css/pages/dripicons.css">
    <link rel="stylesheet" href="<?=ROOT?>/assets/vendors/choices.js/choices.min.css" />
    <link rel="stylesheet" href="<?=ROOT?>/assets/css/custom.css">

    <link rel="stylesheet" type="text/css" href="<?=ROOT?>/assets/css/tree_graph/ECOTree.css">

    <script src="<?=ROOT?>/assets/js/functions.js" type="text/javascript"></script>
    <script src="<?=ROOT?>/assets/js/tree_graph/ECOTree.js" type="text/javascript" ></script>

    <xml:namespace ns="urn:schemas-microsoft-com:vml" prefix="v"/>
    <style>v\:*{ behavior:url(#default#VML);}</style>

    <!-- DISABLE ENTER ON EVERY PAGE -->
    <script>
        window.API_BASE_URL = `<?=ROOT?>`; // baza = /minierp/


        window.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.keyCode === 13) {
                if (e.target.closest('.choices')) return; // allow in choices
                if (e.target.nodeName === 'INPUT' && e.target.type === 'text') {
                    e.preventDefault();
                    return false;
                }
            }
        }, true);
    </script>

    <?php
    if (function_exists('customPageHeader')){
        customPageHeader();
    }?>
</head>
<body>
<div id="app">

    <?php $this->view("page_components/sidebar"); ?>



    <div id="main" class='layout-navbar'>
        <header class='mb-3'>
            <?php $this->view("page_components/navbar"); ?>
        </header>
        <div id="appNotificationBanner" class="px-3 px-md-4" aria-live="polite"></div>
        <div id="main-content">
