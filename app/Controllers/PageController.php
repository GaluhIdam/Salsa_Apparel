<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;

/** Halaman yang tujuannya dibangun di fase berikutnya + 404. */
final class PageController extends Controller
{
    private const STATIC_PAGES = [
        'contact' => 'Contact Us', 'faq' => 'FAQ', 'shipping' => 'Shipping Information',
        'returns' => 'Returns & Exchanges', 'size-guide' => 'Size Guide', 'our-story' => 'Our Story',
        'privacy' => 'Privacy Policy', 'terms' => 'Terms & Conditions',
        'support' => 'Contact Us',
    ];

    public function comingSoon(string $title, string $message = 'This page is being prepared and will be available soon.'): void
    {
        $this->view('pages/coming-soon', ['pageTitle' => $title, 'heading' => $title, 'message' => $message]);
    }

    public function staticPage(string $slug): void
    {
        if (!isset(self::STATIC_PAGES[$slug])) {
            $this->notFound();
            return;
        }

        if ($slug === 'our-story') {
            $this->view('pages/our-story', [
                'pageTitle' => 'About Salsa Apparel',
            ]);
            return;
        }

        if ($slug === 'contact') {
            $this->view('pages/support', [
                'pageTitle' => 'Contact Us',
            ]);
            return;
        }
        $this->comingSoon(self::STATIC_PAGES[$slug]);

        if ($slug === 'support') {
            $this->view('pages/support', [
                'pageTitle' => 'Contact Us',
            ]);
            return;
        }
}

    public function notFound(): void
    {
        $this->view('pages/error', [
            'pageTitle' => 'Page not found',
            'heading' => 'Page not found',
            'message' => 'The page you are looking for does not exist or has moved.',
        ], 404);
    }
}
