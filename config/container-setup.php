<?php

declare(strict_types=1);

// Setup mode uses the DB-free shared definitions only. No PDO/repositories/
// CryptoService are needed to render the setup wizard.
return require __DIR__ . '/container-shared.php';
