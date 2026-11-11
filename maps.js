(() => {
    const defaultCenter = [0.5071, 100.4478];
    const tileLayerUrl =
        'https://api.maptiler.com/maps/streets-v4/{z}/{x}/{y}.png?key=';
    const mapTilerAttribution =
        '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> ' +
        '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';
    const openStreetMapAttribution =
        '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

    function addMapTiles(map, apiKey, container) {
        const usingMapTiler = Boolean(apiKey);
        const tiles = usingMapTiler
            ? L.tileLayer(`${tileLayerUrl}${encodeURIComponent(apiKey)}`, {
                maxZoom: 20,
                tileSize: 512,
                zoomOffset: -1,
                attribution: mapTilerAttribution
            })
            : L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: openStreetMapAttribution
            });
        tiles.on('tileerror', () => {
            setStatus(
                container,
                usingMapTiler
                    ? 'MapTiler gagal memuat peta. Periksa API key dan pembatasan domain.'
                    : 'Peta gagal dimuat. Periksa koneksi internet lalu coba lagi.'
            );
        });
        tiles.addTo(map);
    }

    function setStatus(container, message) {
        const status = container.querySelector('[data-map-status]');
        if (status) {
            status.textContent = message;
        }
    }

    function parseCoordinate(value) {
        if (value === '' || value === null) {
            return null;
        }
        const coordinate = Number(value);
        return Number.isFinite(coordinate) ? coordinate : null;
    }

    function hasValidCoordinates(latitude, longitude) {
        return latitude !== null &&
            longitude !== null &&
            latitude >= -90 &&
            latitude <= 90 &&
            longitude >= -180 &&
            longitude <= 180;
    }

    function watchLocation(container, onSuccess) {
        if (!navigator.geolocation) {
            setStatus(container, 'Browser ini tidak mendukung akses lokasi.');
            return;
        }

        setStatus(container, 'Meminta izin lokasi perangkat...');
        navigator.geolocation.getCurrentPosition(
            onSuccess,
            (error) => {
                const message = error.code === error.PERMISSION_DENIED
                    ? 'Akses lokasi ditolak. Izinkan lokasi di browser lalu coba lagi.'
                    : 'Lokasi tidak dapat ditemukan. Pastikan GPS aktif dan coba lagi.';
                setStatus(container, message);
            },
            { enableHighAccuracy: true, timeout: 30000, maximumAge: 0 }
        );
    }

    function initializePicker(element) {
        if (element.dataset.mapInitialized === 'true') {
            return;
        }
        element.dataset.mapInitialized = 'true';
        const container = element.closest('[data-address-card]') || element.closest('form');
        const apiKey = element.dataset.maptilerKey;
        const locationLabel = element.hasAttribute('data-address-map') ? 'alamat' : 'toko';

        const latitudeInput =
            container.querySelector('[data-address-latitude]') ||
            container.querySelector('[name="latitude"]');
        const longitudeInput =
            container.querySelector('[data-address-longitude]') ||
            container.querySelector('[name="longitude"]');
        const addressInput =
            container.querySelector('[data-address-street]') ||
            container.querySelector('[name="alamat"]');
        const addressStatus = container.querySelector('[data-address-status]');
        const latitude = parseCoordinate(element.dataset.lat);
        const longitude = parseCoordinate(element.dataset.lng);
        const hasLocation = hasValidCoordinates(latitude, longitude);
        const regionSelect =
            container.querySelector('[data-address-region]') ||
            container.querySelector('#map-region-select');
        const selectedRegionOption = regionSelect?.selectedOptions[0];
        const selectedRegionLatitude = parseCoordinate(selectedRegionOption?.dataset.lat);
        const selectedRegionLongitude = parseCoordinate(selectedRegionOption?.dataset.lng);
        const hasSelectedRegion = Boolean(regionSelect?.value) &&
            hasValidCoordinates(selectedRegionLatitude, selectedRegionLongitude);
        const initialCenter = hasLocation
            ? [latitude, longitude]
            : hasSelectedRegion
                ? [selectedRegionLatitude, selectedRegionLongitude]
                : defaultCenter;
        const initialZoom = hasLocation ? 16 : hasSelectedRegion ? 13 : 12;
        const map = L.map(element).setView(
            initialCenter,
            initialZoom
        );

        addMapTiles(map, apiKey, container);

        let marker = null;
        let addressController = null;

        function setLocation(latlng) {
            const lat = latlng.lat.toFixed(7);
            const lng = latlng.lng.toFixed(7);
            latitudeInput.value = lat;
            longitudeInput.value = lng;
            element.dataset.lat = lat;
            element.dataset.lng = lng;

            if (marker) {
                marker.setLatLng(latlng);
            } else {
                marker = L.marker(latlng).addTo(map);
            }

            setStatus(container, `Titik ${locationLabel}: ${lat}, ${lng}`);
        }

        const islandSelect =
            container.querySelector('[data-address-island]') ||
            container.querySelector('#map-island-select');
        const provinceSelect =
            container.querySelector('[data-address-province]') ||
            container.querySelector('#map-province-select');
        function selectedAreaNames() {
            const regionName = regionSelect?.selectedOptions[0]?.textContent.trim();
            const provinceName = provinceSelect?.selectedOptions[0]?.textContent.trim();
            return [regionName, provinceName, 'Indonesia'].filter(Boolean);
        }

        function addressWithArea(address) {
            const baseAddress = address.trim();
            const parts = baseAddress ? [baseAddress] : [];
            const normalizedAddress = baseAddress.toLocaleLowerCase('id-ID');

            selectedAreaNames().forEach((area, index) => {
                const searchName = index === 0
                    ? area.replace(/^(kabupaten|kota administrasi|kota)\s+/i, '')
                    : area;
                if (
                    !normalizedAddress ||
                    !normalizedAddress.includes(searchName.toLocaleLowerCase('id-ID'))
                ) {
                    parts.push(area);
                }
            });

            return parts.filter(Boolean).join(', ');
        }

        function fillAddressFromRegion() {
            const regionName = regionSelect?.selectedOptions[0]?.textContent.trim();
            if (!regionName || !regionSelect.value) {
                return;
            }

            if (addressController) {
                addressController.abort();
            }
            addressInput.value = addressWithArea('');
            if (addressStatus) {
                addressStatus.textContent =
                    'Alamat wilayah terisi. Pilih titik peta untuk melengkapi nama jalan.';
            }
        }

        async function reverseGeocode(latlng) {
            if (!apiKey) {
                if (addressStatus) {
                    addressInput.value = addressWithArea(addressInput.value);
                    addressStatus.textContent =
                        'Titik sudah dipilih. Lengkapi detail jalan secara manual; provinsi dan kota/kabupaten ditambahkan otomatis.';
                }
                return;
            }
            if (addressController) {
                addressController.abort();
            }
            addressController = new AbortController();
            if (addressStatus) {
                addressStatus.textContent = 'Mencari alamat dari titik koordinat...';
            }

            const params = new URLSearchParams({
                key: apiKey,
                language: 'id',
                limit: '1'
            });
            const url =
                `https://api.maptiler.com/geocoding/${latlng.lng},${latlng.lat}.json?${params}`;

            try {
                const response = await fetch(url, {
                    signal: addressController.signal
                });
                if (!response.ok) {
                    throw new Error('Layanan pencarian alamat tidak tersedia.');
                }

                const result = await response.json();
                const feature = result.features?.[0];
                const placeName = feature?.place_name || feature?.text;
                if (!placeName) {
                    throw new Error('Alamat jalan tidak ditemukan di sekitar titik ini.');
                }

                addressInput.value = addressWithArea(placeName);
                if (addressStatus) {
                    addressStatus.textContent =
                        'Alamat jalan dan kota/kabupaten berhasil diisi dari titik peta.';
                }
            } catch (error) {
                if (error.name !== 'AbortError' && addressStatus) {
                    addressInput.value = addressWithArea('');
                    addressStatus.textContent =
                        `${error.message} Alamat wilayah tetap terisi; kamu dapat melengkapinya manual.`;
                }
            }
        }

        function filterOptions(select, attribute, value, placeholder) {
            const options = Array.from(select.options);
            options.forEach((option, index) => {
                if (index === 0) {
                    option.hidden = false;
                    return;
                }
                option.hidden = option.dataset[attribute] !== value;
            });
            select.value = '';
            select.disabled = value === '';
            select.options[0].textContent = placeholder;
        }

        islandSelect?.addEventListener('change', () => {
            filterOptions(
                provinceSelect,
                'island',
                islandSelect.value,
                islandSelect.value ? 'Pilih provinsi...' : 'Pilih pulau terlebih dahulu...'
            );
            filterOptions(
                regionSelect,
                'province',
                '',
                'Pilih provinsi terlebih dahulu...'
            );
        });

        provinceSelect?.addEventListener('change', () => {
            filterOptions(
                regionSelect,
                'province',
                provinceSelect.value,
                provinceSelect.value
                    ? 'Pilih kota/kabupaten...'
                    : 'Pilih provinsi terlebih dahulu...'
            );
        });

        regionSelect?.addEventListener('change', () => {
            const option = regionSelect.selectedOptions[0];
            const latitude = parseCoordinate(option?.dataset.lat);
            const longitude = parseCoordinate(option?.dataset.lng);
            if (!hasValidCoordinates(latitude, longitude)) {
                if (option?.value) {
                    setStatus(container, 'Koordinat kota/kabupaten tidak valid.');
                }
                return;
            }

            if (marker) {
                map.removeLayer(marker);
                marker = null;
            }
            latitudeInput.value = '';
            longitudeInput.value = '';
            element.dataset.lat = '';
            element.dataset.lng = '';
            fillAddressFromRegion();
            map.setView([latitude, longitude], 13);
            setStatus(
                container,
                `Peta menuju ${option.textContent.trim()}. Klik titik ${locationLabel} yang tepat pada peta.`
            );
        });

        map.on('click', (event) => {
            setLocation(event.latlng);
            reverseGeocode(event.latlng);
        });
        if (islandSelect?.value) {
            const initialProvince = provinceSelect.value;
            const initialRegion = regionSelect.value;
            islandSelect.dispatchEvent(new Event('change'));
            if (initialProvince) {
                provinceSelect.value = initialProvince;
                provinceSelect.dispatchEvent(new Event('change'));
            }
            if (initialRegion) {
                regionSelect.value = initialRegion;
                regionSelect.dispatchEvent(new Event('change'));
            }
        } else if (provinceSelect?.value && regionSelect) {
            const selectedRegion = regionSelect.value;
            Array.from(regionSelect.options).forEach((option, index) => {
                if (index > 0) {
                    option.hidden = option.dataset.province !== provinceSelect.value;
                }
            });
            if (
                selectedRegion &&
                regionSelect.selectedOptions[0]?.dataset.province === provinceSelect.value
            ) {
                regionSelect.value = selectedRegion;
            } else if (selectedRegion) {
                regionSelect.value = '';
            }
        }
        if (regionSelect?.value && !hasLocation) {
            regionSelect.dispatchEvent(new Event('change'));
        }
        if (hasLocation) {
            setLocation(L.latLng(latitude, longitude));
            map.setView([latitude, longitude], 16);
        }

        const locateButton = container.querySelector(
            `[data-use-current-location="${element.id}"]`
        );
        locateButton?.addEventListener('click', () => {
            watchLocation(container, (position) => {
                const latlng = L.latLng(
                    position.coords.latitude,
                    position.coords.longitude
                );
                map.setView(latlng, 17);
                setLocation(latlng);
                reverseGeocode(latlng);
            });
        });

        window.setTimeout(() => map.invalidateSize(), 0);
    }

    function initializeShopMap(element) {
        if (element.dataset.mapInitialized === 'true') {
            return;
        }
        element.dataset.mapInitialized = 'true';
        const container = element.closest('.directions-panel');
        const apiKey = element.dataset.maptilerKey;

        const data = document.getElementById('shop-map-data');
        const shops = data ? JSON.parse(data.textContent) : [];
        const validShops = shops.filter((shop) =>
            hasValidCoordinates(shop.latitude, shop.longitude)
        );
        const map = L.map(element).setView(defaultCenter, 12);

        addMapTiles(map, apiKey, container);

        const markers = [];
        let currentLocation = null;
        let currentMarker = null;
        let roadAccessMarker = null;
        let destinationMarker = null;
        let snapLines = [];
        let routeLine = null;
        let routeController = null;

        async function showRoute(shop) {
            if (!currentLocation) {
                setStatus(container, 'Temukan lokasi kamu terlebih dahulu untuk melihat rute.');
                return;
            }

            if (routeController) {
                routeController.abort();
            }
            routeController = new AbortController();
            setStatus(container, `Mencari rute ke ${shop.name}...`);

            const coordinates = [
                `${currentLocation.lng},${currentLocation.lat}`,
                `${shop.longitude},${shop.latitude}`
            ].join(';');

            try {
                const response = await fetch(
                    `https://router.project-osrm.org/route/v1/driving/${coordinates}?overview=full&geometries=geojson`,
                    { signal: routeController.signal }
                );
                if (!response.ok) {
                    throw new Error('Layanan rute tidak tersedia.');
                }

                const result = await response.json();
                if (!result.routes || !result.routes.length) {
                    throw new Error('Rute berkendara tidak ditemukan.');
                }

                const route = result.routes[0];
                const waypoints = result.waypoints || [];
                if (
                    waypoints.length < 2 ||
                    !waypoints[0].location ||
                    !waypoints[1].location
                ) {
                    throw new Error('Titik terdekat di jalan tidak ditemukan.');
                }

                const snappedStart = L.latLng(
                    waypoints[0].location[1],
                    waypoints[0].location[0]
                );
                const snappedEnd = L.latLng(
                    waypoints[1].location[1],
                    waypoints[1].location[0]
                );

                if (routeLine) {
                    map.removeLayer(routeLine);
                }
                if (roadAccessMarker) {
                    map.removeLayer(roadAccessMarker);
                }
                if (destinationMarker) {
                    map.removeLayer(destinationMarker);
                }
                snapLines.forEach((line) => map.removeLayer(line));
                snapLines = [];

                routeLine = L.geoJSON(route.geometry, {
                    style: { color: '#2563eb', weight: 6, opacity: 0.8 }
                }).addTo(map);
                roadAccessMarker = L.circleMarker(snappedStart, {
                    radius: 7,
                    color: '#fff',
                    weight: 2,
                    fillColor: '#16a34a',
                    fillOpacity: 1
                }).addTo(map).bindPopup('Titik jalan terdekat dari lokasi kamu');
                destinationMarker = L.circleMarker(snappedEnd, {
                    radius: 7,
                    color: '#fff',
                    weight: 2,
                    fillColor: '#dc2626',
                    fillOpacity: 1
                }).addTo(map).bindPopup(`Titik jalan terdekat ke ${shop.name}`);

                const userSnapDistance = currentLocation.distanceTo(snappedStart);
                const shopLocation = L.latLng(shop.latitude, shop.longitude);
                const shopSnapDistance = shopLocation.distanceTo(snappedEnd);
                if (userSnapDistance > 15) {
                    snapLines.push(L.polyline(
                        [currentLocation, snappedStart],
                        { color: '#475569', dashArray: '5 7', weight: 2 }
                    ).addTo(map));
                }
                if (shopSnapDistance > 15) {
                    snapLines.push(L.polyline(
                        [shopLocation, snappedEnd],
                        { color: '#475569', dashArray: '5 7', weight: 2 }
                    ).addTo(map));
                }
                map.fitBounds(routeLine.getBounds(), { padding: [30, 30] });

                const distanceKm = (route.distance / 1000).toFixed(1);
                const durationMinutes = Math.round(route.duration / 60);
                const snapDistance = Math.round(userSnapDistance);
                const snapMessage = userSnapDistance > 30
                    ? ` Lokasi kamu ${snapDistance} m dari jalan; rute dimulai dari jalan terdekat.`
                    : '';
                setStatus(
                    container,
                    `Rute ke ${shop.name}: ${distanceKm} km, sekitar ${durationMinutes} menit berkendara.${snapMessage}`
                );
            } catch (error) {
                if (error.name !== 'AbortError') {
                    setStatus(container, `${error.message} Coba lagi sebentar.`);
                }
            }
        }

        validShops.forEach((shop) => {
            const marker = L.marker([shop.latitude, shop.longitude]).addTo(map);
            const popup = document.createElement('div');
            const name = document.createElement('strong');
            const address = document.createElement('p');
            const details = document.createElement('a');

            name.textContent = shop.name;
            address.textContent = shop.address;
            details.textContent = 'Lihat toko';
            details.href = `detail_toko.php?id=${encodeURIComponent(shop.id)}`;
            popup.append(name, address, details);
            marker.bindPopup(popup);
            marker.on('click', () => showRoute(shop));
            markers.push(marker);
        });

        if (markers.length) {
            map.fitBounds(L.featureGroup(markers).getBounds().pad(0.15));
        }

        container.querySelector('[data-locate-user]')?.addEventListener(
            'click',
            () => watchLocation(container, (position) => {
                currentLocation = L.latLng(
                    position.coords.latitude,
                    position.coords.longitude
                );

                if (currentMarker) {
                    currentMarker.setLatLng(currentLocation);
                } else {
                    currentMarker = L.circleMarker(currentLocation, {
                        radius: 9,
                        color: '#fff',
                        weight: 3,
                        fillColor: '#2563eb',
                        fillOpacity: 1
                    }).addTo(map).bindPopup('Lokasi kamu');
                }

                map.setView(currentLocation, 14);

                if (!markers.length) {
                    setStatus(container, 'Belum ada toko dengan titik lokasi yang bisa dirutekan.');
                    return;
                }

                const nearestShop = validShops.reduce((nearest, shop) => {
                    const distance = currentLocation.distanceTo([
                        shop.latitude,
                        shop.longitude
                    ]);
                    return !nearest || distance < nearest.distance
                        ? { shop, distance }
                        : nearest;
                }, null);
                showRoute(nearestShop.shop);
            })
        );

        window.setTimeout(() => map.invalidateSize(), 0);
    }

    function initializeMaps(root = document) {
        if (!window.L) {
            root.querySelectorAll(
                '[data-location-picker] [data-map-status], [data-shops-map] [data-map-status]'
            ).forEach((status) => {
                status.textContent = 'Peta gagal dimuat. Periksa koneksi internet lalu muat ulang.';
            });
            return;
        }

        root.querySelectorAll('[data-location-picker]').forEach(initializePicker);
        root.querySelectorAll('[data-shops-map]').forEach((element) => {
            if (
                element.hasAttribute('data-map-lazy') &&
                element.dataset.mapOpen !== 'true'
            ) {
                return;
            }
            initializeShopMap(element);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initializeMaps());
    } else {
        initializeMaps();
    }
    window.initializeLocationPicker = (element) => {
        if (!window.L) {
            setStatus(
                element.closest('[data-address-card]') || element.closest('form'),
                'Peta gagal dimuat. Periksa koneksi internet lalu muat ulang halaman.'
            );
            return;
        }
        initializePicker(element);
    };
    window.initializeShopLocationMap = (element) => {
        if (!window.L) {
            setStatus(
                element.closest('.directions-panel'),
                'Peta gagal dimuat. Periksa koneksi internet lalu muat ulang halaman.'
            );
            return;
        }
        initializeShopMap(element);
    };
    new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node instanceof Element) {
                    initializeMaps(node);
                }
            });
        });
    }).observe(document.documentElement, { childList: true, subtree: true });
})();
