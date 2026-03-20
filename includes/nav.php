<?php
// $activePage should be set before including this file
$activePage = $activePage ?? '';
?>
<header class="site-header">
    <a href="/index.php" class="logo">⏱ Gleitzeit</a>
    <button class="nav-toggle" aria-label="Menü" onclick="this.closest('.site-header').classList.toggle('nav-open')">
        <span></span><span></span><span></span>
    </button>
    <nav>
        <a href="/index.php"      <?= $activePage === 'dashboard' ? 'class="active"' : '' ?>>Dashboard</a>
        <a href="/month.php"      <?= $activePage === 'month'     ? 'class="active"' : '' ?>>Monat</a>
        <a href="/absences.php"   <?= $activePage === 'absences'  ? 'class="active"' : '' ?>>Abwesenheiten</a>
        <a href="/export.php"     <?= $activePage === 'export'    ? 'class="active"' : '' ?>>Export</a>
        <a href="/import.php"     <?= $activePage === 'import'    ? 'class="active"' : '' ?>>Import</a>
        <a href="/settings.php"   <?= $activePage === 'settings'  ? 'class="active"' : '' ?>>Einstellungen</a>
        <a href="/logout.php" class="nav-logout">Logout</a>
    </nav>
</header>
