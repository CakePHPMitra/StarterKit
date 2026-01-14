<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link      https://cakephp.org CakePHP(tm) Project
 * @since     0.2.9
 * @license   https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App\Controller;

use Cake\Core\Configure;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use Cake\View\Exception\MissingTemplateException;

/**
 * Static content controller
 *
 * This controller will render views from templates/Pages/
 *
 * @link https://book.cakephp.org/5/en/controllers/pages-controller.html
 */
class PagesController extends AppController
{
    /**
     * Displays a view
     *
     * @param string ...$path Path segments.
     * @return \Cake\Http\Response|null
     * @throws \Cake\Http\Exception\ForbiddenException When a directory traversal attempt.
     * @throws \Cake\View\Exception\MissingTemplateException When the view file could not
     *   be found and in debug mode.
     * @throws \Cake\Http\Exception\NotFoundException When the view file could not
     *   be found and not in debug mode.
     * @throws \Cake\View\Exception\MissingTemplateException In debug mode.
     */
    public function display(string ...$path): ?Response
    {
        if (!$path) {
            return $this->redirect('/');
        }
        if (in_array('..', $path, true) || in_array('.', $path, true)) {
            throw new ForbiddenException();
        }
        $page = $subpage = null;

        if (!empty($path[0])) {
            $page = $path[0];
        }
        if (!empty($path[1])) {
            $subpage = $path[1];
        }
        $this->set(compact('page', 'subpage'));

        try {
            return $this->render(implode('/', $path));
        } catch (MissingTemplateException $exception) {
            if (Configure::read('debug')) {
                throw $exception;
            }
            throw new NotFoundException();
        }
    }

    /**
     * Health check page - demonstrates SPA navigation
     *
     * @return void
     */
    public function health(): void
    {
        $this->set('status', 'healthy');
        $this->set('checks', [
            'php' => PHP_VERSION,
            'cakephp' => Configure::version(),
            'database' => $this->checkDatabase(),
        ]);
    }

    /**
     * Counter increment - demonstrates SPA reactive updates
     *
     * @return \Cake\Http\Response|null
     */
    public function increment()
    {
        $count = $this->request->getSession()->read('counter', 0) + 1;
        $this->request->getSession()->write('counter', $count);

        return $this->Spa->respond(['count' => $count]);
    }

    /**
     * Counter decrement
     *
     * @return \Cake\Http\Response|null
     */
    public function decrement()
    {
        $count = $this->request->getSession()->read('counter', 0) - 1;
        $this->request->getSession()->write('counter', $count);

        return $this->Spa->respond(['count' => $count]);
    }

    /**
     * Counter reset
     *
     * @return \Cake\Http\Response|null
     */
    public function reset()
    {
        $this->request->getSession()->write('counter', 0);

        return $this->Spa->respond(['count' => 0]);
    }

    /**
     * Check database connection status
     *
     * @return string
     */
    protected function checkDatabase(): string
    {
        try {
            $connection = \Cake\Datasource\ConnectionManager::get('default');
            $connection->getDriver()->connect();

            return 'connected';
        } catch (\Exception $e) {
            return 'not configured';
        }
    }
}
