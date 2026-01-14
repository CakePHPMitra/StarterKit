<?php

/**
 * CakePHP Home Page
 *
 * @var \App\View\AppView $this
 */

$this->assign('title', 'Welcome');
$this->assign('description', 'Welcome to Cakephp5 - A modern lightweight PHP application.');
?>

<div class="hero text-center" style="padding: 60px 20px;">
    <h1 style="font-size: 2.5rem; margin-bottom: 20px; color: #333;">
        Welcome to CakePHP
    </h1>
    <p style="font-size: 1.2rem; color: #666; max-width: 600px; margin: 0 auto 30px;">
        A modern lightweight PHP application.
    </p>
    <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
        <a href="https://book.cakephp.org/5/" target="_blank" rel="noopener" class="btn btn-primary">
            CakePHP Documentation
        </a>
    </div>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-top: 40px;">
    <div style="background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <h3 style="margin-bottom: 15px; color: #9b4dca;">CakePHP 5</h3>
        <p style="color: #666; line-height: 1.6;">
            Built on CakePHP 5, providing a robust MVC framework with conventions over configuration,
            powerful ORM, and comprehensive security features.
        </p>
    </div>
    <div style="background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <h3 style="margin-bottom: 15px; color: #00b894;">DDEV Ready</h3>
        <p style="color: #666; line-height: 1.6;">
            Preconfigured for DDEV development environment with automatic Vite dev server startup,
            making local development a breeze.
        </p>
    </div>
    <div style="background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <h3 style="margin-bottom: 15px; color: #fd79a8;">Vite Integration</h3>
        <p style="color: #666; line-height: 1.6;">
            Modern frontend asset bundling with Hot Module Replacement (HMR).
            Changes to CSS and JS are reflected instantly without page reload.
        </p>
    </div>
</div>

<div style="margin-top: 40px;">
    <h2 style="margin-bottom: 20px; color: #333;">Vite HMR Demo</h2>
    <div class="vite-demo-box">
        <h4>Hot Module Replacement Active</h4>
        <p>Edit <code>resources/css/app.css</code> to change this box's style instantly!</p>
    </div>
    <p style="margin-top: 15px; color: #666; font-size: 1rem;">
        Try changing the gradient colors in <code>resources/css/app.css</code> while running <code>npm run dev</code> to see HMR in action.
    </p>
</div>