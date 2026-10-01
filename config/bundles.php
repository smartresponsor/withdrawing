<?php

declare(strict_types=1);

$bundles = [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    App\Withdrawing\WithdrawingBundle::class => ['all' => true],
];

if (class_exists(Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class)) {
    $bundles[Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class] = ['all' => true];
}

return $bundles;
