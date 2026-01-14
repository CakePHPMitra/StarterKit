<?php
/**
 * CakePHP Home Page
 *
 * @var \App\View\AppView $this
 */

$this->assign('title', 'Welcome');
$this->assign('description', 'CakePHP 5 StarterKit - A modern PHP application with DDEV, Vite & SPA.');

$count = $this->request->getSession()->read('counter', 0);
?>

<style>
.feature-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-top: 40px;
}
.feature-card {
    background: white;
    padding: 25px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.feature-card.full-width {
    grid-column: span 2;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
}
.feature-card h3 {
    margin-bottom: 15px;
}
.feature-card > p,
.feature-card > div > p {
    color: #666;
    line-height: 1.6;
}
@media (max-width: 768px) {
    .feature-grid {
        grid-template-columns: 1fr;
    }
    .feature-card.full-width {
        grid-column: span 1;
        grid-template-columns: 1fr;
    }
}
</style>

<div class="hero text-center" style="padding: 60px 20px;">
    <h1 style="font-size: 2.5rem; margin-bottom: 20px; color: #333;">
        Welcome to CakePHP 5 StarterKit
    </h1>
    <p style="color: #666; max-width: 600px; margin: 0 auto 30px;">
        A production-ready starter kit with DDEV, Vite HMR, server-driven SPA architecture, and database-driven configuration.
    </p>
    <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
        <a href="https://book.cakephp.org/5/" target="_blank" rel="noopener" class="btn btn-primary">
            CakePHP Documentation
        </a>
        <a href="https://github.com/CakePHPMitra/spa" target="_blank" rel="noopener" class="btn">
            CakeSPA Plugin
        </a>
    </div>
</div>

<div class="feature-grid">
    <div class="feature-card">
        <h3 style="color: #9b4dca;">CakePHP 5</h3>
        <p>
            Built on CakePHP 5, providing a robust MVC framework with conventions over configuration,
            powerful ORM, and comprehensive security features.
        </p>
    </div>
    <div class="feature-card">
        <h3 style="color: #00b894;">DDEV Ready</h3>
        <p>
            Preconfigured for DDEV development environment with Redis caching,
            making local development a breeze.
        </p>
    </div>

    <div class="feature-card full-width">
        <div>
            <h3 style="color: #fd79a8;">Vite Integration</h3>
            <p>
                Modern frontend asset bundling with Hot Module Replacement (HMR).
                Changes to CSS and JS are reflected instantly without page reload.
            </p>
            <p style="margin-top: 15px;">
                Try changing the gradient colors in <code>resources/css/app.css</code> while running <code>npm run dev</code>.
            </p>
        </div>
        <div>
            <div class="vite-demo-box">
                <h4>Hot Module Replacement Active</h4>
                <p>Edit <code>resources/css/app.css</code> to see instant style changes!</p>
            </div>
        </div>
    </div>

    <div class="feature-card full-width">
        <div>
            <h3 style="color: #0984e3;">CakeSPA Plugin</h3>
            <p>
                Server-driven SPA architecture. Build reactive applications
                without JavaScript frameworks - Livewire-like reactivity for CakePHP.
            </p>
            <p style="margin-top: 15px;">
                Click the buttons to see reactive updates without page reload.
            </p>
        </div>
        <div>
            <div style="background: #f8f9fa; padding: 20px; border-radius: 8px;">
                <h4 style="margin-bottom: 15px;">Counter Demo</h4>
                <div style="display: inline-flex; align-items: stretch; border-radius: 4px; overflow: hidden; border: 1px solid #ddd;">
                    <?= $this->Spa->button('-', '/counter/decrement', ['class' => 'btn', 'style' => 'border-radius: 0; margin: 0; border: none;']) ?>
                    <span style="display: flex; align-items: center; justify-content: center; font-size: 1.2rem; font-weight: bold; min-width: 50px; padding: 0 15px; background: white;">
                        <?= $this->Spa->target('count', $count) ?>
                    </span>
                    <?= $this->Spa->button('+', '/counter/increment', ['class' => 'btn btn-primary', 'style' => 'border-radius: 0; margin: 0; border: none;']) ?>
                </div>
                <?= $this->Spa->button('Reset', '/counter/reset', ['class' => 'btn btn-outline', 'style' => 'margin-left: 15px;']) ?>
                <p style="margin-top: 15px; margin-bottom: 0;">
                    <?= $this->Spa->navLink('Health Check →', '/health', ['class' => 'btn btn-outline']) ?>
                </p>
            </div>
        </div>
    </div>
</div>
