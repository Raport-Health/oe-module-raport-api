<?php

// SPDX-License-Identifier: MIT

declare(strict_types=1);

$classLoader->registerNamespaceIfNotExists('Raport\\OpenEmr\\', __DIR__ . '/src');
(new \Raport\OpenEmr\Bootstrap())->subscribe($eventDispatcher);
