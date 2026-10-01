<?php
/**
 * UniGo - about page.
 */
declare(strict_types=1);
?>
<section class="section">
    <div class="section__head">
        <div>
            <h2 class="section__title">About <?= e(app_name()) ?></h2>
            <p class="text-sm text-muted-2 mb-0">An integrated smart transport system for people and parcels.</p>
        </div>
    </div>

    <div class="card mb-5">
        <div class="card__body">
            <p>
                <?= e(app_name()) ?> brings passenger travel, freight delivery and fleet oversight together on a
                single platform. A passenger searches and books a seat; a driver sees the manifest and navigates;
                an operator runs their fleet and revenue; the transport authority monitors the whole network and
                coordinates emergencies.
            </p>
            <p class="mb-0">
                It is designed for Kampala first and structured to expand to any city. This build runs entirely on
                simulated data so the full experience can be evaluated safely.
            </p>
        </div>
    </div>

    <div class="grid grid-2 gap-3">
        <article class="card">
            <div class="card__body">
                <span class="stat__icon mb-2"><i class="icon" data-icon="target">target</i></span>
                <h3 class="card__title">Our mission</h3>
                <p class="text-sm text-muted-2 mb-0">
                    Make daily movement predictable, affordable and safe by connecting every mode of transport
                    and every operator on one trusted network.
                </p>
            </div>
        </article>
        <article class="card">
            <div class="card__body">
                <span class="stat__icon mb-2"><i class="icon" data-icon="layers">layers</i></span>
                <h3 class="card__title">What makes it different</h3>
                <p class="text-sm text-muted-2 mb-0">
                    Booking, live tracking, parcel delivery, digital payments, safety alerts and analytics are
                    native parts of the platform, not add-ons bolted on later.
                </p>
            </div>
        </article>
    </div>

    <div class="section__head mt-5">
        <div>
            <h2 class="section__title">Transport modes</h2>
            <p class="text-sm text-muted-2 mb-0">Every way the city moves, in one search.</p>
        </div>
    </div>
    <div class="grid grid-auto-sm gap-3">
        <?php
        $modes = [
            ['bus', 'Bus', 'Scheduled intercity and city routes'],
            ['bolt', 'Electric bus', 'Low-emission urban transit'],
            ['car', 'Taxi', 'On-demand licensed taxis'],
            ['moto', 'Boda-boda', 'Fast two-wheeled rides'],
            ['users', 'Shared ride', 'Split the fare, split the cost'],
            ['truck', 'Delivery', 'Parcels and freight on the fleet'],
        ];
        ?>
        <?php foreach ($modes as [$icon, $name, $text]): ?>
            <article class="card card--flat">
                <div class="card__body">
                    <span class="stat__icon mb-2"><i class="icon" data-icon="<?= e($icon) ?>"><?= e($icon) ?></i></span>
                    <h3 class="card__title" style="font-size:var(--fs-base)"><?= e($name) ?></h3>
                    <p class="text-sm text-muted-2 mb-0"><?= e($text) ?></p>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
