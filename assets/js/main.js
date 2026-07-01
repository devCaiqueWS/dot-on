/* ═══════════════════════════════════════════════════════════════════════════
   DOT-ON · main.js — Interações da landing page
   ═══════════════════════════════════════════════════════════════════════════ */

(function () {
    'use strict';

    // ─── 1. Mobile menu toggle ─────────────────────────────────────────────
    const btnMobile  = document.getElementById('btn-mobile');
    const menuMobile = document.getElementById('menu-mobile');
    const iconMenu   = document.getElementById('icon-menu');
    const iconClose  = document.getElementById('icon-close');

    if (btnMobile && menuMobile) {
        btnMobile.addEventListener('click', () => {
            menuMobile.classList.toggle('hidden');
            iconMenu.classList.toggle('hidden');
            iconClose.classList.toggle('hidden');
        });

        // Fechar menu ao clicar em um link
        menuMobile.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', () => {
                menuMobile.classList.add('hidden');
                iconMenu.classList.remove('hidden');
                iconClose.classList.add('hidden');
            });
        });
    }

    // ─── 2. Navbar com sombra ao scroll ────────────────────────────────────
    const navbar = document.getElementById('navbar');
    if (navbar) {
        const handleScroll = () => {
            if (window.scrollY > 10) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        };
        window.addEventListener('scroll', handleScroll, { passive: true });
        handleScroll();
    }

    // ─── 3. FAQ: fechar outros details ao abrir um ─────────────────────────
    const faqDetails = document.querySelectorAll('#faq details');
    faqDetails.forEach(detail => {
        detail.addEventListener('toggle', () => {
            if (detail.open) {
                faqDetails.forEach(other => {
                    if (other !== detail) other.open = false;
                });
            }
        });
    });

    // ─── 4. Smooth scroll para âncoras ─────────────────────────────────────
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            const targetId = this.getAttribute('href');
            if (targetId === '#') return;
            const target = document.querySelector(targetId);
            if (target) {
                e.preventDefault();
                const offset = 80; // altura da navbar
                const top = target.getBoundingClientRect().top + window.pageYOffset - offset;
                window.scrollTo({ top, behavior: 'smooth' });
            }
        });
    });

    // ─── 5. Animação fade-in ao entrar no viewport (Intersection Observer) ──
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animate-fade-in-up');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

        // Observar todos os cards e títulos de seção
        document.querySelectorAll('section h2, section .grid > *').forEach(el => {
            observer.observe(el);
        });
    }

    // ─── 6. Console branding (easter egg) ──────────────────────────────────
    if (window.console) {
        console.log(
            '%c DOT-ON %c · Ponto eletrônico moderno ',
            'background:#1E40AF;color:white;padding:6px 12px;border-radius:6px 0 0 6px;font-weight:bold;font-size:14px',
            'background:#10B981;color:white;padding:6px 12px;border-radius:0 6px 6px 0;font-size:14px'
        );
        console.log(
            '%cDesenvolvido por Social Business Serviço\n%chttps://socialbusiness.com.br',
            'color:#1E40AF;font-weight:bold',
            'color:#10B981'
        );
    }

})();
