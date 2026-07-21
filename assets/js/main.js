/**
 * Themify front-end JS. Framework-free, deferred, < 3 KB.
 * Handles: mobile menu, sticky header state, TOC scrollspy + toggle,
 * back-to-top button, and lazy hydration of details/accordions.
 */
( function () {
	'use strict';

	var cfg = window.themifyData || {};

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) { fn(); }
		else { document.addEventListener( 'DOMContentLoaded', fn ); }
	}

	ready( function () {
		mobileMenu();
		stickyHeader();
		backToTop();
		toc();
		sliders();
		shareBar();
		authorTabs();
		copyLinks();
		youtube();
	} );

	/* ---- YouTube section: click a thumbnail to play inline (no iframe until then) ---- */
	function youtube() {
		var grid = document.querySelector( '[data-tf-youtube]' );
		if ( ! grid ) { return; }
		grid.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '.tf-youtube__video' ) : null;
			if ( ! btn || btn.querySelector( 'iframe' ) ) { return; }
			var id = btn.getAttribute( 'data-video' );
			if ( ! id ) { return; }
			var iframe = document.createElement( 'iframe' );
			iframe.setAttribute( 'src', 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent( id ) + '?autoplay=1&rel=0' );
			iframe.setAttribute( 'title', 'YouTube video player' );
			iframe.setAttribute( 'allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture' );
			iframe.setAttribute( 'allowfullscreen', '' );
			btn.innerHTML = '';
			btn.appendChild( iframe );
		} );
	}

	/* ---- Author box tabs (Author / Recent Posts) ---- */
	function authorTabs() {
		var boxes = document.querySelectorAll( '[data-tf-authorbox]' );
		Array.prototype.forEach.call( boxes, function ( box ) {
			var tabs = box.querySelectorAll( '.tf-authorbox__tab' );
			var panels = box.querySelectorAll( '.tf-authorbox__panel' );
			if ( tabs.length < 2 ) { return; }
			// Initial state (progressive enhancement): the markup ships with every
			// panel visible so it stays readable with JS off; here we hide the
			// inactive one now that we can switch between them.
			Array.prototype.forEach.call( panels, function ( p ) {
				if ( ! p.classList.contains( 'is-active' ) ) { p.setAttribute( 'hidden', '' ); }
			} );
			Array.prototype.forEach.call( tabs, function ( tab ) {
				tab.addEventListener( 'click', function () {
					var name = tab.getAttribute( 'data-tab' );
					Array.prototype.forEach.call( tabs, function ( t ) {
						var on = t === tab;
						t.classList.toggle( 'is-active', on );
						t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
					} );
					Array.prototype.forEach.call( panels, function ( p ) {
						var on = p.getAttribute( 'data-panel' ) === name;
						p.classList.toggle( 'is-active', on );
						if ( on ) { p.removeAttribute( 'hidden' ); } else { p.setAttribute( 'hidden', '' ); }
					} );
				} );
			} );
		} );
	}

	/* ---- Floating share bar: open/close toggle + copy-link ---- */
	function shareBar() {
		var bar = document.querySelector( '[data-tf-share]' );
		if ( ! bar ) { return; }
		var toggle = bar.querySelector( '.tf-share__toggle' );

		if ( toggle ) {
			toggle.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				var open = bar.classList.toggle( 'is-open' );
				toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			} );
			// Close on outside click / Escape.
			document.addEventListener( 'click', function ( e ) {
				if ( bar.classList.contains( 'is-open' ) && ! bar.contains( e.target ) ) {
					bar.classList.remove( 'is-open' );
					toggle.setAttribute( 'aria-expanded', 'false' );
				}
			} );
			document.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Escape' && bar.classList.contains( 'is-open' ) ) {
					bar.classList.remove( 'is-open' );
					toggle.setAttribute( 'aria-expanded', 'false' );
				}
			} );
		}

	}

	/* ---- Copy-link buttons (corner share bar + in-article share) ---- */
	function copyLinks() {
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '[data-tf-copy-link]' ) : null;
			if ( ! btn ) { return; }
			e.preventDefault();
			var url = btn.getAttribute( 'data-tf-copy-link' ) || window.location.href;
			var cls = btn.className.indexOf( 'tf-artshare' ) !== -1 ? 'tf-artshare__btn--copied' : 'tf-share__btn--copied';
			var done = function () {
				btn.setAttribute( 'data-copied', 'Copied!' );
				btn.classList.add( cls );
				window.setTimeout( function () { btn.classList.remove( cls ); }, 1600 );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( url ).then( done, done );
			} else {
				var ta = document.createElement( 'textarea' );
				ta.value = url; ta.style.position = 'fixed'; ta.style.opacity = '0';
				document.body.appendChild( ta ); ta.select();
				try { document.execCommand( 'copy' ); } catch ( err ) {}
				document.body.removeChild( ta ); done();
			}
		} );
	}

	/* ---- Hero banner slider (crossfade, auto-advance, library-free) ---- */
	function sliders() {
		var nodes = document.querySelectorAll( '.tf-slider' );
		Array.prototype.forEach.call( nodes, function ( slider ) {
			var slides = slider.querySelectorAll( '.tf-slide' );
			if ( slides.length < 2 ) { return; }
			var dots = slider.querySelectorAll( '.tf-slider__dot' );
			var i = 0, timer = null;
			var delay = parseInt( slider.getAttribute( 'data-autoplay' ), 10 ) || 6000;
			var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

			function show( n ) {
				slides[ i ].classList.remove( 'is-active' );
				if ( dots[ i ] ) { dots[ i ].classList.remove( 'is-active' ); }
				i = ( n + slides.length ) % slides.length;
				slides[ i ].classList.add( 'is-active' );
				if ( dots[ i ] ) { dots[ i ].classList.add( 'is-active' ); }
			}
			function start() { if ( reduce ) { return; } stop(); timer = window.setInterval( function () { show( i + 1 ); }, delay ); }
			function stop() { if ( timer ) { window.clearInterval( timer ); timer = null; } }

			var next = slider.querySelector( '.tf-slider__nav--next' );
			var prev = slider.querySelector( '.tf-slider__nav--prev' );
			if ( next ) { next.addEventListener( 'click', function () { show( i + 1 ); start(); } ); }
			if ( prev ) { prev.addEventListener( 'click', function () { show( i - 1 ); start(); } ); }
			Array.prototype.forEach.call( dots, function ( dot, idx ) {
				dot.addEventListener( 'click', function () { show( idx ); start(); } );
			} );
			slider.addEventListener( 'mouseenter', stop );
			slider.addEventListener( 'mouseleave', start );
			start();
		} );
	}

	/* ---- Mobile menu ---- */
	function mobileMenu() {
		var toggle = document.querySelector( '.tf-nav-toggle' );
		var menu = document.querySelector( '.tf-menu' );
		if ( ! toggle || ! menu ) { return; }
		toggle.addEventListener( 'click', function () {
			var open = menu.classList.toggle( 'is-open' );
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			document.body.style.overflow = open ? 'hidden' : '';
		} );
		// Close on outside click / escape.
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && menu.classList.contains( 'is-open' ) ) {
				menu.classList.remove( 'is-open' );
				toggle.setAttribute( 'aria-expanded', 'false' );
				document.body.style.overflow = '';
			}
		} );
	}

	/* ---- Sticky header shadow ---- */
	function stickyHeader() {
		var header = document.querySelector( '.tf-site-header' );
		if ( ! header ) { return; }
		if ( cfg.stickyNav ) { header.classList.add( 'is-sticky' ); }
		var last = 0;
		window.addEventListener( 'scroll', function () {
			var y = window.pageYOffset;
			header.classList.toggle( 'is-scrolled', y > 12 );
			last = y;
		}, { passive: true } );
	}

	/* ---- Back to top ---- */
	function backToTop() {
		var btn = document.querySelector( '.tf-back-to-top' );
		if ( ! btn ) { return; }
		window.addEventListener( 'scroll', function () {
			btn.classList.toggle( 'is-visible', window.pageYOffset > 600 );
		}, { passive: true } );
		btn.addEventListener( 'click', function () {
			window.scrollTo( { top: 0, behavior: 'smooth' } );
		} );
	}

	/* ---- TOC toggle + scrollspy ---- */
	function toc() {
		var nav = document.querySelector( '.tf-toc' );
		if ( ! nav ) { return; }

		var toggle = nav.querySelector( '.tf-toc__toggle' );
		if ( toggle ) {
			toggle.addEventListener( 'click', function () {
				var expanded = toggle.getAttribute( 'aria-expanded' ) !== 'false';
				toggle.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
				nav.hidden = false;
				var list = nav.querySelector( 'ol' );
				if ( list ) { list.style.display = expanded ? 'none' : ''; }
			} );
		}

		var links = Array.prototype.slice.call( nav.querySelectorAll( 'a[href^="#"]' ) );
		if ( ! links.length || ! ( 'IntersectionObserver' in window ) ) { return; }
		var map = {};
		links.forEach( function ( a ) {
			var id = decodeURIComponent( a.getAttribute( 'href' ).slice( 1 ) );
			var el = document.getElementById( id );
			if ( el ) { map[ id ] = a; }
		} );
		var obs = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					links.forEach( function ( a ) { a.classList.remove( 'is-active' ); } );
					var active = map[ entry.target.id ];
					if ( active ) { active.classList.add( 'is-active' ); }
				}
			} );
		}, { rootMargin: '-20% 0px -70% 0px' } );
		Object.keys( map ).forEach( function ( id ) {
			var el = document.getElementById( id );
			if ( el ) { obs.observe( el ); }
		} );
	}
} )();

/* Similar Posts carousel — arrows, dots, snap paging. */
(function () {
	function initSimilar(section) {
		var viewport = section.querySelector('.tf-similar__viewport');
		var dotsWrap = section.querySelector('.tf-similar__dots');
		var prev = section.querySelector('.tf-similar__arrow--prev');
		var next = section.querySelector('.tf-similar__arrow--next');
		if (!viewport) { return; }

		function pageCount() {
			return Math.max(1, Math.round(viewport.scrollWidth / viewport.clientWidth));
		}
		function currentPage() {
			return Math.min(pageCount() - 1, Math.round(viewport.scrollLeft / viewport.clientWidth));
		}
		function goTo(page) {
			viewport.scrollTo({ left: page * viewport.clientWidth, behavior: 'smooth' });
		}
		function buildDots() {
			if (!dotsWrap) { return; }
			dotsWrap.innerHTML = '';
			var n = pageCount();
			for (var i = 0; i < n; i++) {
				var b = document.createElement('button');
				b.type = 'button';
				b.className = 'tf-similar__dot' + (i === currentPage() ? ' is-active' : '');
				b.setAttribute('aria-label', 'Go to slide ' + (i + 1));
				(function (idx) { b.addEventListener('click', function () { goTo(idx); }); })(i);
				dotsWrap.appendChild(b);
			}
			if (n < 2) { dotsWrap.style.display = 'none'; if (prev) { prev.style.display = 'none'; } if (next) { next.style.display = 'none'; } }
			else { dotsWrap.style.display = ''; if (prev) { prev.style.display = ''; } if (next) { next.style.display = ''; } }
		}
		function syncDots() {
			if (!dotsWrap) { return; }
			var cur = currentPage();
			var dots = dotsWrap.children;
			for (var i = 0; i < dots.length; i++) {
				dots[i].classList.toggle('is-active', i === cur);
			}
		}

		if (prev) { prev.addEventListener('click', function () { goTo(Math.max(0, currentPage() - 1)); }); }
		if (next) { next.addEventListener('click', function () { goTo(Math.min(pageCount() - 1, currentPage() + 1)); }); }

		var scrollT;
		viewport.addEventListener('scroll', function () {
			clearTimeout(scrollT);
			scrollT = setTimeout(syncDots, 80);
		}, { passive: true });

		var resizeT;
		window.addEventListener('resize', function () {
			clearTimeout(resizeT);
			resizeT = setTimeout(buildDots, 150);
		});

		buildDots();
	}

	function boot() {
		var sections = document.querySelectorAll('.tf-similar');
		for (var i = 0; i < sections.length; i++) { initSimilar(sections[i]); }
	}
	if (document.readyState !== 'loading') { boot(); } else { document.addEventListener('DOMContentLoaded', boot); }
})();

/* Sticky trending-posts bar — slides in after a little scrolling. */
(function () {
	function boot() {
		var bar = document.querySelector('.tf-postbar');
		if (!bar) { return; }
		var shown = false;
		function onScroll() {
			var y = window.scrollY || document.documentElement.scrollTop || 0;
			var v = y > 350;
			if (v !== shown) {
				shown = v;
				bar.classList.toggle('is-visible', v);
			}
		}
		window.addEventListener('scroll', onScroll, { passive: true });
		onScroll();
	}
	if (document.readyState !== 'loading') { boot(); } else { document.addEventListener('DOMContentLoaded', boot); }
})();

/* Image share icons — hover buttons over content images, popup share. */
(function () {
	var ICONS = {
		pinterest: 'M12 2C6.48 2 2 6.48 2 12c0 4.24 2.64 7.86 6.36 9.31-.09-.79-.17-2.01.03-2.87.18-.78 1.17-4.97 1.17-4.97s-.3-.6-.3-1.48c0-1.39.81-2.43 1.81-2.43.85 0 1.27.64 1.27 1.41 0 .86-.55 2.14-.83 3.33-.24 1 .5 1.81 1.48 1.81 1.78 0 3.15-1.88 3.15-4.59 0-2.4-1.72-4.07-4.18-4.07-2.85 0-4.52 2.14-4.52 4.35 0 .86.33 1.78.75 2.28.08.1.09.19.07.29-.08.31-.25 1-.28 1.14-.04.19-.15.23-.34.14-1.25-.58-2.03-2.41-2.03-3.87 0-3.15 2.29-6.04 6.6-6.04 3.46 0 6.16 2.47 6.16 5.77 0 3.44-2.17 6.21-5.18 6.21-1.01 0-1.96-.53-2.29-1.15l-.62 2.37c-.22.87-.83 1.96-1.24 2.62.93.29 1.92.45 2.94.45 5.52 0 10-4.48 10-10S17.52 2 12 2z',
		facebook: 'M24 12.07C24 5.41 18.63 0 12 0S0 5.41 0 12.07C0 18.1 4.39 23.09 10.13 24v-8.44H7.08v-3.49h3.04V9.41c0-3.02 1.79-4.7 4.53-4.7 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.95.93-1.95 1.89v2.26h3.32l-.53 3.49h-2.79V24C19.61 23.09 24 18.1 24 12.07z',
		x: 'M18.24 2.25h3.31l-7.23 8.26 8.5 11.24h-6.66l-5.21-6.82-5.97 6.82H1.67l7.73-8.84L1.25 2.25h6.83l4.71 6.23 5.45-6.23zm-1.16 17.52h1.83L7.08 4.13H5.12l11.96 15.64z',
		whatsapp: 'M17.47 14.38c-.3-.15-1.77-.87-2.04-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.64.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.07-.15-.67-1.62-.92-2.22-.24-.58-.49-.5-.67-.51-.17-.01-.37-.01-.57-.01-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48 0 1.46 1.06 2.87 1.21 3.07.15.2 2.09 3.19 5.06 4.47.71.31 1.26.49 1.69.62.71.23 1.36.2 1.87.12.57-.09 1.77-.72 2.02-1.42.25-.7.25-1.3.17-1.42-.07-.12-.27-.2-.57-.35zM12.05 21.79h-.01a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.88 9.9-9.88a9.83 9.83 0 0 1 7 2.9 9.83 9.83 0 0 1 2.89 7c0 5.45-4.44 9.87-9.9 9.87zm8.42-18.29A11.82 11.82 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.9c0 2.1.55 4.14 1.59 5.95L.06 24l6.3-1.65a11.88 11.88 0 0 0 5.68 1.45h.01c6.55 0 11.89-5.34 11.89-11.9 0-3.18-1.24-6.16-3.47-8.4z',
		telegram: 'M11.94 0C5.35 0 0 5.35 0 11.94s5.35 11.94 11.94 11.94 11.94-5.35 11.94-11.94S18.53 0 11.94 0zm5.85 8.16-1.96 9.25c-.15.66-.54.82-1.09.51l-2.99-2.2-1.44 1.39c-.16.16-.29.29-.6.29l.21-3.05 5.55-5.02c.24-.21-.05-.33-.37-.12l-6.86 4.32-2.96-.92c-.64-.2-.66-.64.14-.95l11.57-4.46c.54-.2 1.01.12.8.96z',
		linkedin: 'M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28zM5.34 7.43a2.06 2.06 0 1 1 0-4.13 2.06 2.06 0 0 1 0 4.13zM7.12 20.45H3.56V9h3.56v11.45zM22.22 0H1.77C.79 0 0 .77 0 1.72v20.55C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.73V1.72C24 .77 23.2 0 22.22 0z',
		reddit: 'M24 12c0-1.38-1.12-2.5-2.5-2.5-.68 0-1.29.27-1.74.71-1.7-1.17-4.02-1.92-6.6-2.01l1.34-4.22 3.61.85a1.75 1.75 0 1 0 .17-1.09l-4.1-.96a.55.55 0 0 0-.65.36L11.9 8.2c-2.64.06-5.01.81-6.75 2-.45-.44-1.06-.71-1.74-.71A2.5 2.5 0 0 0 .91 14.2c-.04.24-.06.49-.06.74 0 3.79 4.55 6.86 10.15 6.86s10.15-3.07 10.15-6.86c0-.25-.02-.49-.06-.73A2.5 2.5 0 0 0 24 12zM6.22 14.31a1.75 1.75 0 1 1 3.5 0 1.75 1.75 0 0 1-3.5 0zm9.87 4.62c-1.2 1.2-3.49 1.29-4.16 1.29-.68 0-2.97-.09-4.16-1.29a.46.46 0 0 1 .65-.64c.75.75 2.36 1.02 3.51 1.02s2.76-.27 3.51-1.02a.46.46 0 0 1 .65.64zm-.31-2.87a1.75 1.75 0 1 1 0-3.5 1.75 1.75 0 0 1 0 3.5z',
		tumblr: 'M14.56 24c-3.6 0-6.29-1.85-6.29-6.29V10.6H5.99V6.75c3.6-.93 5.1-4.03 5.28-6.75h3.74v6.12h4.37v4.48h-4.37v6.19c0 1.86.94 2.5 2.43 2.5h2.12V24h-5z',
		email: 'M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4-8 5-8-5V6l8 5 8-5v2z'
	};

	function boot() {
		var cfg = window.tfImgShare;
		if (!cfg || !cfg.networks || !cfg.networks.length) { return; }

		var imgs = document.querySelectorAll('.tf-content img, .tf-article__hero img');
		for (var i = 0; i < imgs.length; i++) { wrap(imgs[i], cfg); }
	}

	function wrap(img, cfg) {
		if (img.closest('.tf-imgshare') || img.width < 200) { return; }

		var holder = document.createElement('span');
		holder.className = 'tf-imgshare';
		img.parentNode.insertBefore(holder, img);
		holder.appendChild(img);

		var btns = document.createElement('span');
		btns.className = 'tf-imgshare__btns';

		for (var i = 0; i < cfg.networks.length; i++) {
			(function (net) {
				var b = document.createElement('button');
				b.type = 'button';
				b.className = 'tf-imgshare__btn';
				b.style.background = net.color || '#374151';
				b.setAttribute('aria-label', 'Share on ' + net.name);
				b.title = 'Share on ' + net.name;
				if (net.icon && ICONS[net.icon]) {
					b.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="' + ICONS[net.icon] + '"/></svg>';
				} else {
					var letter = document.createElement('span');
					letter.textContent = (net.name || '?').charAt(0).toUpperCase();
					b.appendChild(letter);
				}
				b.addEventListener('click', function (e) {
					e.preventDefault();
					e.stopPropagation();
					var image = img.currentSrc || img.src || '';
					var share = net.url
						.replace(/\{url\}/g, encodeURIComponent(window.location.href))
						.replace(/\{image\}/g, encodeURIComponent(image))
						.replace(/\{title\}/g, encodeURIComponent(cfg.title || document.title));
					if (share.indexOf('{') === -1 && net.url.indexOf('{') === -1) {
						// No placeholders in the template — append the page URL.
						share += (share.indexOf('?') === -1 ? '?' : '&') + 'u=' + encodeURIComponent(window.location.href);
					}
					window.open(share, 'tfshare', 'width=760,height=620,noopener');
				});
				btns.appendChild(b);
			})(cfg.networks[i]);
		}

		holder.appendChild(btns);
	}

	if (document.readyState !== 'loading') { boot(); } else { document.addEventListener('DOMContentLoaded', boot); }
})();
