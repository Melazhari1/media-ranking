const { createApp, ref, onMounted, computed, watch } = Vue;

createApp({
    setup() {

        // ─── State ───────────────────────────────────────────────────────────

        const items          = ref([]);
        const categories     = ref([]);
        const ratings        = ref([]);
        const searchQuery    = ref('');
        const selectedGenres   = ref([]);
        const selectedStatuses = ref([]);
        const selectedRatings  = ref([]);
        const orderBy        = ref('m.created_at DESC');
        const loading        = ref(false);
        const isSidebarOpen  = ref(false);
        const isDarkMode     = ref(localStorage.getItem('theme') === 'dark');

        const currentPage  = ref(1);
        const pageSize     = ref(20);
        const totalItems   = ref(0);
        const totalPages   = ref(0);

        // Info modal
        const showInfoModal  = ref(false);
        const selectedMedia  = ref(null);
        const isSavingInfo   = ref(false);

        // Add/Edit modal
        const showMediaModal = ref(false);
        const isSavingMedia  = ref(false);
        const selectedFile   = ref(null);
        const imagePreview   = ref(null);

        const mediaForm = ref({
            id:          null,
            title:       '',
            image:       '',
            year:        String(new Date().getFullYear()),
            score:       0,
            score_mal:   0,
            status:      null,
            infos:       '',
            category_id: null,
            rating_id:   null,
        });

        const searchActive = computed(() =>
            searchQuery.value.trim() !== ''  ||
            selectedGenres.value.length > 0  ||
            selectedRatings.value.length > 0 ||
            selectedStatuses.value.length > 0
        );

        let debounceTimer = null;

        // ─── Media list ──────────────────────────────────────────────────────

        const fetchMedia = async (page = 1) => {
            loading.value    = true;
            currentPage.value = typeof page === 'number' ? page : 1;

            try {
                const params = new URLSearchParams({
                    page:     currentPage.value,
                    limit:    pageSize.value,
                    order_by: orderBy.value,
                });

                if (searchQuery.value)              params.append('keyword',      searchQuery.value);
                if (selectedRatings.value.length)   params.append('rating_ids',   selectedRatings.value.join(','));
                if (selectedGenres.value.length)    params.append('category_ids', selectedGenres.value.join(','));
                if (selectedStatuses.value.length)  params.append('statuses',     selectedStatuses.value.join(','));

                const response = await fetch('api.php?' + params.toString());
                const result   = await response.json();

                if (result.status === 'success') {
                    items.value = result.data;
                    if (result.pagination) {
                        totalItems.value  = result.pagination.total;
                        totalPages.value  = result.pagination.pages;
                        currentPage.value = result.pagination.page;
                    } else {
                        totalItems.value = items.value.length;
                        totalPages.value = 1;
                    }
                }
            } catch (error) {
                console.error('Error fetching media:', error);
            } finally {
                loading.value = false;
            }
        };

        const changePage = (page) => {
            if (page < 1 || page > totalPages.value) return;
            fetchMedia(page);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        };

        const fetchRandomMedia = async () => {
            loading.value = true;
            try {
                const response = await fetch('api.php?action=random&limit=20');
                const result   = await response.json();
                if (result.status === 'success') {
                    items.value      = result.data;
                    totalItems.value = result.data.length;
                    totalPages.value = 1;
                    currentPage.value = 1;
                    // Clear filters so the random selection isn't confusing
                    selectedGenres.value   = [];
                    selectedStatuses.value = [];
                    searchQuery.value      = '';
                }
            } catch (error) {
                console.error('Error fetching random media:', error);
            } finally {
                loading.value = false;
            }
        };

        // ─── Filters & search ────────────────────────────────────────────────

        const fetchFilters = async () => {
            try {
                const [catRes, ratRes] = await Promise.all([
                    fetch('api.php?action=categories'),
                    fetch('api.php?action=ratings'),
                ]);
                const catData = await catRes.json();
                const ratData = await ratRes.json();
                if (catData.status === 'success') categories.value = catData.data;
                if (ratData.status === 'success') ratings.value    = ratData.data;
            } catch (error) {
                console.error('Error fetching filters:', error);
            }
        };

        const resetFilters = () => {
            clearTimeout(debounceTimer); // Prevent stale debounced fetch after reset
            selectedGenres.value   = [];
            selectedStatuses.value = [];
            selectedRatings.value  = [];
            searchQuery.value      = '';
            fetchMedia(1);
        };

        // Watchers automatically re-fetch when filter or sort selections change.
        // The deep flag handles array mutations as well as full replacement.
        watch([selectedRatings, selectedGenres, selectedStatuses, orderBy], () => {
            fetchMedia(1);
        }, { deep: true });

        watch(searchQuery, () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => fetchMedia(1), 500);
        });

        // ─── Status / score quick-updates ────────────────────────────────────

        const isBookCategory = (item) =>
            (item.categories || '').toLowerCase().includes('book');

        const toggleWatchStatus = async (item) => {
            const book       = isBookCategory(item);
            const planStatus = book ? 'Plan to Read'  : 'Plan to Watch';
            const doneStatus = book ? 'Read'          : 'Watched';
            const isPlan     = (s) => s === 'Plan to Watch' || s === 'Plan to Read';

            const next = !item.status ? planStatus : isPlan(item.status) ? doneStatus : null;

            try {
                const response = await fetch(`api.php?action=update_status&id=${item.id}`, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({ status: next }),
                });
                const result = await response.json();
                if (result.status === 'success') {
                    item.status = next; // Optimistic local update
                }
            } catch (error) {
                console.error('Error updating status:', error);
            }
        };

        const updateScore = async (item) => {
            try {
                const response = await fetch(`api.php?action=update_score&id=${item.id}`, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({ score: item.score }),
                });
                const result = await response.json();
                if (result.status !== 'success') {
                    console.error('Error updating score:', result.message);
                }
            } catch (error) {
                console.error('Error updating score:', error);
            }
        };

        // ─── Info modal ──────────────────────────────────────────────────────

        const openInfoModal = async (item) => {
            try {
                const response = await fetch(`api.php?id=${item.id}`);
                const result   = await response.json();
                selectedMedia.value = result.status === 'success' ? result.data : { ...item };
            } catch {
                selectedMedia.value = { ...item };
            }
            showInfoModal.value = true;
        };

        const closeInfoModal = () => {
            showInfoModal.value  = false;
            selectedMedia.value  = null;
        };

        const saveInfos = async () => {
            if (!selectedMedia.value) return;
            isSavingInfo.value = true;
            try {
                const response = await fetch(`api.php?action=update_infos&id=${selectedMedia.value.id}`, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({ infos: selectedMedia.value.infos }),
                });
                const result = await response.json();
                if (result.status === 'success') {
                    const index = items.value.findIndex(i => i.id === selectedMedia.value.id);
                    if (index !== -1) {
                        items.value[index].infos = selectedMedia.value.infos;
                    }
                    closeInfoModal();
                } else {
                    alert('Error saving notes: ' + result.message);
                }
            } catch (error) {
                console.error('Error saving info:', error);
                alert('An error occurred while saving notes.');
            } finally {
                isSavingInfo.value = false;
            }
        };

        // ─── Add/Edit modal ──────────────────────────────────────────────────

        const openMediaModal = async (item = null) => {
            selectedFile.value  = null;
            imagePreview.value  = item?.image ? 'medias/' + item.image : null;

            if (item) {
                try {
                    const response = await fetch(`api.php?id=${item.id}`);
                    const result   = await response.json();
                    if (result.status === 'success') {
                        const d = result.data;
                        mediaForm.value = {
                            id:          d.id,
                            title:       d.title,
                            image:       d.image,
                            year:        d.year,
                            score:       parseFloat(d.score)     || 0,
                            score_mal:   parseFloat(d.score_mal) || 0,
                            status:      d.status,
                            infos:       d.infos,
                            category_id: d.category_id ? Number(d.category_id) : null,
                            rating_id:   d.rating_id   ? Number(d.rating_id)   : null,
                        };
                    }
                } catch (error) {
                    console.error('Error fetching media details:', error);
                    mediaForm.value = {
                        ...item,
                        category_id: item.category_id ? Number(item.category_id) : null,
                        rating_id:   item.rating_id   ? Number(item.rating_id)   : null,
                    };
                }
            } else {
                mediaForm.value = {
                    id:          null,
                    title:       '',
                    image:       '',
                    year:        String(new Date().getFullYear()),
                    score:       0,
                    score_mal:   0,
                    status:      null,
                    infos:       '',
                    category_id: null,
                    rating_id:   null,
                };
            }
            showMediaModal.value = true;
        };

        const closeMediaModal = () => {
            showMediaModal.value = false;
        };

        const handleFileUpload = (event) => {
            const file = event.target.files[0];
            if (!file) return;
            selectedFile.value = file;
            const reader = new FileReader();
            reader.onload = (e) => { imagePreview.value = e.target.result; };
            reader.readAsDataURL(file);
        };

        const saveMedia = async () => {
            // Client-side validation
            if (!mediaForm.value.title.trim()) {
                alert('Title is required.');
                return;
            }

            isSavingMedia.value = true;
            try {
                const isUpdate = !!mediaForm.value.id;
                const url      = isUpdate ? `api.php?id=${mediaForm.value.id}` : 'api.php';

                const formData = new FormData();
                formData.append('title',        mediaForm.value.title.trim());
                formData.append('year',         mediaForm.value.year);
                formData.append('score',        mediaForm.value.score);
                formData.append('score_mal',    mediaForm.value.score_mal);
                // Send empty string for null status; PHP converts it back to NULL
                formData.append('status',       mediaForm.value.status ?? '');
                formData.append('infos',        mediaForm.value.infos ?? '');
                formData.append('category_ids', mediaForm.value.category_id ?? '');
                formData.append('rating_ids',   mediaForm.value.rating_id   ?? '');

                if (selectedFile.value) {
                    formData.append('image_file', selectedFile.value);
                } else {
                    formData.append('image', mediaForm.value.image ?? '');
                }

                const response = await fetch(url, { method: 'POST', body: formData });
                const result   = await response.json();

                if (result.status === 'success') {
                    closeMediaModal();
                    fetchMedia(isUpdate ? currentPage.value : 1);
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (error) {
                console.error('Error saving media:', error);
                alert('An error occurred while saving.');
            } finally {
                isSavingMedia.value = false;
            }
        };

        // ─── Delete ──────────────────────────────────────────────────────────

        const deleteMedia = async (id) => {
            if (!confirm('Are you sure you want to delete this media?')) return;
            try {
                const response = await fetch(`api.php?id=${id}`, { method: 'DELETE' });
                const result   = await response.json();
                if (result.status === 'success') {
                    fetchMedia(currentPage.value);
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (error) {
                console.error('Error deleting media:', error);
            }
        };

        // Category-aware status labels for the Add/Edit modal
        const isBookFormCategory = computed(() => {
            const cat = categories.value.find(c => c.id === mediaForm.value.category_id);
            return cat ? cat.name.toLowerCase().includes('book') : false;
        });

        // ─── Theme ───────────────────────────────────────────────────────────

        const applyTheme = () => {
            document.documentElement.classList.toggle('dark-theme', isDarkMode.value);
        };

        const toggleTheme = () => {
            isDarkMode.value = !isDarkMode.value;
            localStorage.setItem('theme', isDarkMode.value ? 'dark' : 'light');
            applyTheme();
        };

        const toggleSidebar = () => {
            isSidebarOpen.value = !isSidebarOpen.value;
        };

        // ─── Init ────────────────────────────────────────────────────────────

        onMounted(() => {
            applyTheme();
            fetchMedia();
            fetchFilters();
        });

        // ─── Expose to template ──────────────────────────────────────────────

        return {
            // List state
            items, loading, currentPage, pageSize, totalItems, totalPages,
            // Filters & search
            categories, ratings, searchQuery, selectedGenres, selectedStatuses, selectedRatings,
            orderBy, searchActive,
            // Pagination
            changePage,
            // Actions
            fetchMedia, fetchRandomMedia, resetFilters,
            toggleWatchStatus, updateScore, deleteMedia,
            // Info modal
            showInfoModal, selectedMedia, isSavingInfo,
            openInfoModal, closeInfoModal, saveInfos,
            // Add/Edit modal
            showMediaModal, mediaForm, isSavingMedia, imagePreview, isBookFormCategory,
            openMediaModal, closeMediaModal, handleFileUpload, saveMedia,
            // UI
            isSidebarOpen, isDarkMode, toggleSidebar, toggleTheme,
        };
    }
}).mount('#app');
