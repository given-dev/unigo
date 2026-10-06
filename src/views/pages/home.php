<?php
declare(strict_types=1);
$companies=$companies ?? []; $routes=$routes ?? [];
?>
<section class="landing-hero">
 <div class="landing-hero__copy">
  <span class="landing-eyebrow">YOUR JOURNEY STARTS HERE</span>
  <h1>Different companies.<br><span>One easy journey.</span></h1>
  <p>Find your route, compare travel companies, and reserve your seat. Travel across Uganda with everything in one place.</p>
  <a class="btn btn--light" href="#travel-companies">Explore travel companies <i class="icon icon--sm" data-icon="arrow-right">arrow-right</i></a>
 </div>
 <div class="journey-visual" aria-hidden="true">
  <div class="journey-visual__line"></div>
  <span class="journey-point journey-point--start">Kampala</span><span class="journey-point journey-point--end">Your next destination</span>
  <div class="journey-ticket"><span class="landing-eyebrow">LET’S GO PLACES</span><strong>Good journeys.<br>Simple bookings.</strong><div class="journey-ticket__bottom"><span>Search · Choose · Book</span><i class="icon" data-icon="bus">bus</i></div></div>
 </div>
</section>
<section class="landing-search" aria-label="Find a trip">
 <form method="get" action="<?= e(url('/trips/search')) ?>" class="landing-search__form">
  <div class="field"><label for="home-from">Leaving from</label><input class="input" id="home-from" name="from" placeholder="Departure town" autocomplete="off"></div>
  <div class="field"><label for="home-to">Going to</label><input class="input" id="home-to" name="to" placeholder="Destination town" autocomplete="off"></div>
  <div class="field"><label for="home-date">Travel date</label><input class="input" type="date" id="home-date" name="date" min="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>"></div>
  <div class="field"><label for="home-company">Travel company</label><select class="select" id="home-company" name="operator_id"><option value="">All companies</option><?php foreach($companies as $company): ?><option value="<?= (int)$company['id'] ?>"><?= e($company['company_name']) ?></option><?php endforeach; ?></select></div>
  <button class="btn btn--primary" type="submit"><i class="icon" data-icon="search">search</i> Find a trip</button>
 </form>
 <p class="landing-search__note">Browse trips freely. Sign in when you’re ready to choose a seat.</p>
</section>
<section class="landing-section" id="travel-companies">
 <div class="landing-section__head"><div><span class="landing-eyebrow">MORE CHOICE, LESS HASSLE</span><h2>Travel companies, together.</h2><p>Explore the operators available on UniGo and find their upcoming trips.</p></div><a class="btn btn--ghost" href="<?= e(url('/trips/search')) ?>">Browse all trips <i class="icon icon--sm" data-icon="arrow-right">arrow-right</i></a></div>
 <?php if($companies): ?><div class="company-grid">
 <?php foreach($companies as $index=>$company): ?>
 <article class="company-card"><div class="company-card__top"><span class="company-avatar company-avatar--<?= $index%4 ?>"><?= e(mb_strtoupper(mb_substr($company['company_name'],0,1))) ?></span><span class="company-card__category">Travel operator</span></div><h3><?= e($company['company_name']) ?></h3><p><?= (int)$company['route_count'] ?> active route<?= (int)$company['route_count']===1?'':'s' ?> <span>·</span> <?= (int)$company['upcoming_trips'] ?> upcoming trip<?= (int)$company['upcoming_trips']===1?'':'s' ?></p><a class="company-card__link" href="<?= e(url('/trips/search?operator_id='.(int)$company['id'])) ?>">View trips <i class="icon icon--sm" data-icon="arrow-right">arrow-right</i></a></article>
 <?php endforeach; ?></div>
 <?php else: ?><div class="landing-empty">Travel companies will appear here once their accounts are approved. <a href="<?= e(url('/contact')) ?>">Contact us</a> to join UniGo.</div><?php endif; ?>
</section>
<section class="landing-section">
 <div class="landing-section__head"><div><span class="landing-eyebrow">FIND YOUR NEXT STOP</span><h2>Explore popular routes.</h2><p>See destinations, operators, and starting fares at a glance.</p></div></div>
 <div class="route-grid"><?php foreach($routes as $route): ?>
 <article class="landing-route"><div class="landing-route__icon"><i class="icon" data-icon="bus">bus</i></div><p class="landing-route__company"><?= e($route['company_name'] ?? 'Independent operator') ?></p><h3><?= e($route['origin_name']) ?> <span>→</span> <?= e($route['destination_name']) ?></h3><p><?= e(duration_minutes((int)$route['duration_minutes'])) ?> <span>·</span> <?= e((string)$route['distance_km']) ?> km</p><div class="landing-route__bottom"><span><small>Starting fare</small><strong><?= e(money($route['base_fare'])) ?></strong></span><a class="btn btn--secondary btn--sm" href="<?= e(url('/trips/search?'.http_build_query(['from'=>$route['origin_name'],'to'=>$route['destination_name'],'operator_id'=>$route['operator_id']]))) ?>">Find seats</a></div></article>
 <?php endforeach; ?><?php if(!$routes): ?><p class="landing-empty">Published routes will appear here soon.</p><?php endif; ?></div>
</section>
<section class="landing-steps">
 <div><span class="landing-eyebrow">A LITTLE PLANNING. A GREAT JOURNEY.</span><h2>From search to seat,<br>in three simple steps.</h2></div>
 <?php foreach([['01','Find your trip','Choose your towns, travel date, and preferred company.'],['02','Pick your seat','Check the fare and select an available seat.'],['03','You’re ready to go','Confirm your booking and keep your ticket in My bookings.']] as $step): ?><div class="landing-step"><span><?= e($step[0]) ?></span><h3><?= e($step[1]) ?></h3><p><?= e($step[2]) ?></p></div><?php endforeach; ?>
</section>
