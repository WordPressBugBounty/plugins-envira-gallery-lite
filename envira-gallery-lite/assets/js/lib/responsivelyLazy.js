/*
 * Responsively Lazy
 * http://ivopetkov.com/b/lazy-load-responsive-images/
 * Copyright 2015-2016, Ivo Petkov
 * Free to use under the MIT license.
 */

if ( typeof envira_lazy_load !== 'undefined' && envira_lazy_load == 'true' ) {

window.responsivelyLazy = (function () {

    var hasWebPSupport = false;
    var windowWidth = null;
    var windowHeight = null;
    var justifiedReady = false;
    var galleryClass = false;
    var hasIntersectionObserverSupport = typeof IntersectionObserver !== 'undefined';

    var isVisible = function (element) {

        if (windowWidth === null) {

            windowWidth = window.innerWidth || document.documentElement.clientWidth || document.body.clientWidth;
            windowHeight = window.innerHeight || document.documentElement.clientHeight || document.body.clientHeight;

            if (windowWidth === null) {
                return false;
            }
        }

        var rect = element.getBoundingClientRect();

        var elementTop = rect.top;
        var elementLeft = rect.left;
        var elementWidth = rect.width;
        var elementHeight = rect.height;
        var test = elementTop < windowHeight && elementTop + elementHeight > 0 && elementLeft < windowWidth && elementLeft + elementWidth > 0;

        if ( test === false ) {

        }
        return test;


    };

    jQuery.fn.exists = function(){return this.length>0;}

    /**
     * Split a srcset attribute into its candidate strings.
     *
     * A plain comma split breaks any URL that contains a comma, which is how CDN
     * transform parameters are written (/w=2048,format=auto). That produced
     * fragments such as "format=auto" and "fit=crop", which were then requested as
     * relative URLs and failed to load. Candidate boundaries follow the HTML
     * srcset rules instead: only a comma that comes after the URL separates
     * candidates, so commas inside a URL stay part of it.
     *
     * Each candidate is returned whole, still carrying its descriptor. Splitting
     * url from descriptor is left to the caller, which does it at the last
     * whitespace exactly as before, so a URL containing a raw space keeps working.
     *
     * @param {string} value Raw srcset attribute value.
     * @return {Array} Array of candidate strings.
     */
    var parseSrcsetCandidates = function (value) {
        var isWhitespace = function (character) {
            return character === ' ' || character === '\t' || character === '\n' || character === '\r' || character === '\f';
        };

        var candidates = [];
        var length = value.length;
        var index = 0;

        while (index < length) {
            // Skip whitespace and empty candidates.
            while (index < length && (isWhitespace(value.charAt(index)) || value.charAt(index) === ',')) {
                index++;
            }

            if (index >= length) {
                break;
            }

            // The URL is everything up to the next whitespace.
            var urlStart = index;
            while (index < length && !isWhitespace(value.charAt(index))) {
                index++;
            }

            var url = value.slice(urlStart, index);
            var descriptor = '';

            if (url.charAt(url.length - 1) === ',') {
                // A comma attached to the URL ends the candidate, so it carries no
                // descriptor. Matches the old behaviour for "a.jpg, b.jpg".
                url = url.replace(/,+$/, '');
            } else {
                // Everything up to the next comma describes this candidate.
                var descriptorStart = index;
                while (index < length && value.charAt(index) !== ',') {
                    index++;
                }
                descriptor = value.slice(descriptorStart, index).trim();

                if (index < length) {
                    index++;
                }
            }

            if (url.length > 0) {
                candidates.push(descriptor.length > 0 ? url + ' ' + descriptor : url);
            }
        }

        return candidates;
    };

    var updateElement = function (container, element) {
        var options = element.getAttribute('data-envira-srcset');
        if (options !== null) {
            options = options.trim();
            if (options.length > 0) {
                var candidates = parseSrcsetCandidates(options);
                var temp = [];
                var optionsCount = candidates.length;
                for (var j = 0; j < optionsCount; j++) {
                    var option = candidates[j];
                    // Split url from descriptor at the LAST whitespace, unchanged from
                    // the original implementation, so a URL containing a raw space
                    // still parses.
                    var spaceIndex = option.lastIndexOf(' ');
                    if (spaceIndex === -1) {
                        var optionImage = option;
                        var optionWidth = 999998;
                    } else {
                        var optionImage = option.substr(0, spaceIndex);
                        var descriptor = option.substr(spaceIndex + 1);
                        var widthMatch = /^(\d+)w$/i.exec(descriptor);

                        if (widthMatch === null) {
                            // Only width descriptors can be compared with the container.
                            // A density descriptor ("2x", which Lite itself writes on every
                            // image) used to lose its unit and read as a 2px width, so a
                            // hidden container picked the retina file. Density is not
                            // supported here (envira-gallery-plugin#5624), so skip it
                            // quietly; anything else is malformed and worth a warning.
                            if (!/^\d+(\.\d+)?x$/i.test(descriptor) && typeof console !== 'undefined' && console.warn) {
                                console.warn('Envira: could not read the srcset descriptor in "' + option + '", skipping this candidate.');
                            }
                            continue;
                        }

                        var optionWidth = parseInt(widthMatch[1], 10);
                    }
                    var add = false;
                    if (optionImage.indexOf('.webp', optionImage.length - 5) !== -1) {
                        if (hasWebPSupport) {
                            add = true;
                        }
                    } else {
                        add = true;
                    }
                    if (add) {
                        temp.push([optionImage, optionWidth]);
                    }
                }
                if (temp.length === 0 && optionsCount > 0 && typeof console !== 'undefined' && console.warn) {
                    console.warn('Envira: no usable srcset candidate in "' + options + '", loading the full-size image.');
                }
                temp.sort(function (a, b) {
                    if (a[1] < b[1]) {
                        return -1;
                    }
                    if (a[1] > b[1]) {
                        return 1;
                    }
                    if (a[1] === b[1]) {
                        if (b[0].indexOf('.webp', b[0].length - 5) !== -1) {
                            return 1;
                        }
                        if (a[0].indexOf('.webp', a[0].length - 5) !== -1) {
                            return -1;
                        }
                    }
                    return 0;
                });
                options = temp;
            } else {
                options = [];
            }
        } else {
            options = [];
        }

        var containerWidth = container.offsetWidth * window.devicePixelRatio;

        // URLs that already failed for this element. Without this the error listener
        // below re-armed the same request on every scroll and resize, and logged it
        // each time, with nothing to stop it.
        var failedUrls = container.enviraFailedUrls || {};

        var bestSelectedOption = null;
        var optionsCount = options.length;
        for (var j = 0; j < optionsCount; j++) {
            var optionData = options[j];
            if (optionData[1] >= containerWidth && !failedUrls[optionData[0]]) {
                bestSelectedOption = optionData;
                break;
            }
        }

        if (bestSelectedOption === null) {
            bestSelectedOption = [element.getAttribute('data-envira-src'), 999999];
        }

        if (failedUrls[bestSelectedOption[0]]) {
            return;
        }

        if (typeof container.lastSetOption === 'undefined') {
            container.lastSetOption = ['', 0];
        }
        if (container.lastSetOption[1] < bestSelectedOption[1]) {
            var fireEvent = container.lastSetOption[1] === 0;
            var url = bestSelectedOption[0];
            var image = new Image();
            image.addEventListener('load', function () {
                element.setAttribute('srcset', url);
                element.setAttribute('src', url);
                if (fireEvent) {
                    var handler = container.getAttribute('data-onlazyload');
                    if (handler !== null) {
                        (new Function(handler).bind(container))();
                    }
                }
            }, false);
            image.addEventListener('error', function () {
                // Remember the failure so the next candidate (or the full-size
                // original) is tried instead of retrying this one forever.
                container.enviraFailedUrls = container.enviraFailedUrls || {};
                container.enviraFailedUrls[url] = true;

                // A late error from an older request must not reset or replace the
                // one that is now current.
                if (container.lastSetOption[0] !== url) {
                    return;
                }
                container.lastSetOption = ['', 0];

                // And try it now. Nothing else re-runs selection for an image that is
                // already in view (resize and load call run() without a gallery, which
                // returns at once), so it would sit on its placeholder until scrolled
                // out and back. This terminates: every failed URL is skipped, and when
                // the full-size original has failed too updateElement() returns early.
                setTimeout(function () {
                    updateElement(container, element);
                }, 0);
            }, false);

            image.onload = function () {

				if ( container.getAttribute( 'class' ) == 'envira-lazy' && jQuery( container ).not( "img" ) ) {
					// this is a legacy layout
					var the_image     = container.firstElementChild;
					var the_container = container;
					var image_id      = the_image.id;
					var image_src     = the_image.src;
					var gallery_id    = jQuery( the_image ).data( 'envira-gallery-id' );
					var item_id       = jQuery( container ).data( 'envira-item-id' );
					var naturalWidth  = this.naturalWidth;
					var naturalHeight = this.naturalHeight;
				} else {
					// we are going with the automatic
					var the_image     = image;
					var the_container = container;
					var image_id      = container.id;
					var image_src     = container.src;
					var gallery_id    = jQuery( container ).data( 'envira-gallery-id' );
					var item_id       = jQuery( container ).data( 'envira-item-id' );
					var naturalWidth  = this.naturalWidth;
					var naturalHeight = this.naturalHeight;

				}

				/* type check */

				if ( gallery_id === undefined || gallery_id === null ) {
					gallery_id = 0;
				}

				jQuery( document ).trigger(
					{
						type:           'envira_image_lazy_load_complete',
						container:      the_container,
						image_src:      image_src,
						image_id:       image_id,
						item_id:        item_id,
						gallery_id:     gallery_id,
						naturalWidth:   naturalWidth,
						naturalHeight:  naturalHeight,
					}
				);

			}
            image.onerror = function () {
                // Match Pro: log the failure. This was commented out here, so a
                // candidate that failed to load was silent on Lite. The error listener
                // above now records the URL, so this fires once per failed URL rather
                // than on every scroll and resize.
                console.error('Cannot load image: ' + url);
                //do something else...
            }

            // Null assignment could have been for IE, but shows as 404 in Safari web
            // console (although everything else is fine). Pro already dropped it after
            // verifying Chrome/Firefox are clean without it; Lite still ran it, which
            // logged a console error of its own - the very thing
            // awesomemotive/envira-image-cdn#38 asks to be rid of.
            // image.src = null;
            image.src = url;

            container.lastSetOption = bestSelectedOption;

        }
    };

    var updateWindowSize = function () {

        windowWidth = window.innerWidth || document.documentElement.clientWidth || document.body.clientWidth;
        windowHeight = window.innerHeight || document.documentElement.clientHeight || document.body.clientHeight;
    };

    var setGalleryClass = function ( theClass ) {
        galleryClass = theClass;
    }

    var run = function ( galleryClass ) {

        if ( typeof galleryClass === 'undefined' ) {
            return;
        }

        var update = function (elements, unknownHeight) {
            var elementsCount = elements.length;
            for (var i = 0; i < elementsCount; i++) {
                var element = elements[i];
                var container = unknownHeight ? element : element.parentNode;
                if ( isVisible(container) === true ) {
                    updateElement(container, element);
                }
            }
        };
        if ( galleryClass ) {
            if ( typeof galleryClass !== 'string' ) {
                return;
            }

            if ( envira_lazy_load_delay === 'undefined' || envira_lazy_load_initial === false || envira_lazy_load_initial === 'undefined' ) {
                /* if we can't locate these vars, at least define the delay - there is no delay, super fast */
                envira_lazy_load_delay = 0;
            }


            myVar = setTimeout(function () {

                if ( jQuery( galleryClass + ' .envira-lazy > img').exists() ) {
                    update(document.querySelectorAll(galleryClass + ' .envira-lazy > img'), false);
                } else if ( jQuery(  galleryClass + ' img.envira-lazy').exists() ) {
                    update(document.querySelectorAll(galleryClass + ' img.envira-lazy'), true);
                }

                envira_lazy_load_initial == true; // ok, we did the initial load so now delay can happen

            }, envira_lazy_load_delay);




        }
        //update(document.querySelectorAll('img.envira-lazy'), true);
    };

    if ('srcset' in document.createElement('img') && typeof window.devicePixelRatio !== 'undefined' && typeof window.addEventListener !== 'undefined' && typeof document.querySelectorAll !== 'undefined') {

        updateWindowSize();

        var image = new Image();
        image.src = 'data:image/webp;base64,UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoCAAEADMDOJaQAA3AA/uuuAAA=';
        image.onload = image.onerror = function () {
            hasWebPSupport = image.width === 2;
            if (hasIntersectionObserverSupport) {

                var updateIntersectionObservers = function () {
                    var elements = document.querySelectorAll('.envira-lazy');
                    var elementsCount = elements.length;
                    for (var i = 0; i < elementsCount; i++) {
                        var element = elements[i];
                        if (typeof element.responsivelyLazyObserverAttached === 'undefined') {
                            element.responsivelyLazyObserverAttached = true;
                            intersectionObserver.observe(element);
                        }
                    }
                };

                var intersectionObserver = new IntersectionObserver(function (entries) {
                    for (var i in entries) {
                        var entry = entries[i];
                        if (entry.intersectionRatio > 0) {
                            var target = entry.target;
                            if (target.tagName.toLowerCase() !== 'img') {
                                var img = target.querySelector('img');
                                if (img !== null) {
                                    updateElement(target, img);
                                }
                            } else {
                                updateElement(target, target);
                            }
                        }
                    }
                });

                run();

            } else {

                var requestAnimationFrameFunction = window.requestAnimationFrame || window.webkitRequestAnimationFrame || window.mozRequestAnimationFrame || function (callback) {
                    window.setTimeout(callback, 1000 / 60);
                };

                var hasChange = true;
                var runIfHasChange = function () {
                    if ( hasChange ) {
                        hasChange = false;
                        // run();
                    }
                    requestAnimationFrameFunction.call(null, runIfHasChange);
                };



                var setChanged = function () {
                    hasChange = true;
                    runIfHasChange();
                };

                var updateParentNodesScrollListeners = function () {
                    var elements = document.querySelectorAll('.envira-lazy');
                    var elementsCount = elements.length;
                    for (var i = 0; i < elementsCount; i++) {
                        var parentNode = elements[i].parentNode;
                        while (parentNode && parentNode.tagName.toLowerCase() !== 'html') {
                            if (typeof parentNode.responsivelyLazyScrollAttached === 'undefined') {
                                parentNode.responsivelyLazyScrollAttached = true;
                                parentNode.addEventListener('scroll', setChanged);
                            }
                            parentNode = parentNode.parentNode;
                        }
                    }
                };

            }

            var attachEvents = function () {
                if (hasIntersectionObserverSupport) {
                    var resizeTimeout = null;
                }
                window.addEventListener('resize', function () {
                    updateWindowSize();
                    if (hasIntersectionObserverSupport) {
                        window.clearTimeout(resizeTimeout);
                        resizeTimeout = window.setTimeout(function () {
                            run();
                        }, 300);
                    } else {
                        setChanged();
                    }
                });
                if (hasIntersectionObserverSupport) {
                    window.addEventListener('load', run);
                    updateIntersectionObservers();
                } else {
                    window.addEventListener('scroll', setChanged);
                    window.addEventListener('load', setChanged);
                    updateParentNodesScrollListeners();
                }
                if (typeof MutationObserver !== 'undefined') {
                    var observer = new MutationObserver(function () {
                        if (hasIntersectionObserverSupport) {
                            updateIntersectionObservers();
                            run();
                        } else {
                            updateParentNodesScrollListeners();
                            setChanged();
                        }
                    });
                    observer.observe(document.querySelector('body'), {childList: true, subtree: true});
                }
            };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', attachEvents);
            } else {
                attachEvents();
            }
        };
    }

    return {
        'run': run,
        'isVisible': isVisible,
        'setGalleryClass': setGalleryClass
    };

}());

}