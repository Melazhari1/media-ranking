const { createApp, ref, onMounted, computed, watch } = Vue;

createApp({
    setup() {
        const items = ref([]);
        const categories = ref([]);
        const searchQuery = ref('');
        const selectedGenres = ref([]);
        const selectedStatuses = ref([]);
        const selectedRatings = ref([]);
        const loading = ref(false);
        const isSidebarOpen = ref(false);
        const orderBy = ref('m.created_at DESC');
        const searchActive = computed(() => {
            return searchQuery.value.trim() !== '' ||
                selectedGenres.value.length > 0 ||
                selectedRatings.value.length > 0 ||
                selectedStatuses.value.length > 0;
        });
        const isDarkMode = ref(localStorage.getItem('theme') === 'dark');
        const ratings = ref([]);
        const showInfoModal = ref(false);
        const showMediaModal = ref(false);
        const selectedMedia = ref(null);
        const isSavingInfo = ref(false);
        const isSavingMedia = ref(false);
        const selectedFile = ref(null);
        const imagePreview = ref(null);

        const mediaForm = ref({
            id: null,
            title: '',
            image: '',
            year: String(new Date().getFullYear()),
            score: 0,
            score_mal: 0,
            status: null,
            infos: '',
            category_id: null,
            rating_id: null
        });

        const currentPage = ref(1);
        const pageSize = ref(20);
        const totalItems = ref(0);
        const totalPages = ref(0);

        let debounceTimer = null;

        const fetchMedia = async (page = 1) => {
            // Ensure page is a number (handles event objects from @change)
            const pageNum = typeof page === 'number' ? page : 1;
            loading.value = true;
            currentPage.value = pageNum;
            try {
                let params = new URLSearchParams();
                params.append('page', currentPage.value);
                params.append('limit', pageSize.value);
                params.append('order_by', orderBy.value);

                if (searchQuery.value) {
                    params.append('keyword', searchQuery.value);
                }

                if (selectedRatings.value.length > 0) {
                    params.append('rating_ids', selectedRatings.value.join(','));
                }

                if (selectedGenres.value.length > 0) {
                    params.append('category_ids', selectedGenres.value.join(','));
                }

                if (selectedStatuses.value.length > 0) {
                    params.append('statuses', selectedStatuses.value.join(','));
                }

                const url = 'api.php' + (params.toString() ? '?' + params.toString() : '');
                const response = await fetch(url);
                const result = await response.json();
                if (result.status === 'success') {
                    items.value = result.data;
                    if (result.pagination) {
                        totalItems.value = result.pagination.total;
                        totalPages.value = result.pagination.pages;
                        currentPage.value = result.pagination.page;
                    } else {
                        // For random or other actions that might not return pagination
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
                const result = await response.json();
                if (result.status === 'success') {
                    items.value = result.data;
                    // Optional: clear filters to avoid confusion
                    selectedGenres.value = [];
                    selectedStatuses.value = [];
                    searchQuery.value = '';
                }
            } catch (error) {
                console.error('Error fetching random media:', error);
            } finally {
                loading.value = false;
            }
        };

        const toggleWatchStatus = async (item) => {
            let newStatus;
            if (!item.status) {
                newStatus = 'Plan to Watch';
            } else if (item.status === 'Plan to Watch') {
                newStatus = 'Watched';
            } else {
                newStatus = null;
            }

            try {
                const response = await fetch(`api.php?action=update_status&id=${item.id}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ status: newStatus })
                });
                const result = await response.json();
                if (result.status === 'success') {
                    item.status = newStatus; // Optimistic update
                }
            } catch (error) {
                console.error('Error updating status:', error);
            }
        };

        const updateScore = async (item) => {
            try {
                const response = await fetch(`api.php?action=update_score&id=${item.id}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ score: item.score })
                });
                const result = await response.json();
                if (result.status !== 'success') {
                    console.error('Error updating score:', result.message);
                }
            } catch (error) {
                console.error('Error updating score:', error);
            }
        };

        const fetchFilters = async () => {
            try {
                const [catRes, ratRes] = await Promise.all([
                    fetch('api.php?action=categories'),
                    fetch('api.php?action=ratings')
                ]);
                const catData = await catRes.json();
                const ratData = await ratRes.json();

                if (catData.status === 'success') categories.value = catData.data;
                if (ratData.status === 'success') ratings.value = ratData.data;
            } catch (error) {
                console.error('Error fetching filters:', error);
            }
        };

        const debounceSearch = () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                fetchMedia(1);
            }, 500);
        };

        // Watchers to automatically trigger search when filters change
        watch([selectedRatings, selectedGenres, selectedStatuses, orderBy], () => {
            fetchMedia(1);
        }, { deep: true });

        watch(searchQuery, () => {
            debounceSearch();
        });

        const toggleSidebar = () => {
            isSidebarOpen.value = !isSidebarOpen.value;
        };

        const resetFilters = () => {
            selectedGenres.value = [];
            selectedStatuses.value = [];
            selectedRatings.value = [];
            searchQuery.value = '';
            fetchMedia(1);
        };

        const toggleTheme = () => {
            isDarkMode.value = !isDarkMode.value;
            localStorage.setItem('theme', isDarkMode.value ? 'dark' : 'light');
            applyTheme();
        };

        const applyTheme = () => {
            if (isDarkMode.value) {
                document.documentElement.classList.add('dark-theme');
            } else {
                document.documentElement.classList.remove('dark-theme');
            }
        };

        const openInfoModal = (item) => {
            selectedMedia.value = { ...item };
            showInfoModal.value = true;
        };

        const closeInfoModal = () => {
            showInfoModal.value = false;
            selectedMedia.value = null;
        };

        const saveInfos = async () => {
            if (!selectedMedia.value) return;
            isSavingInfo.value = true;
            try {
                const response = await fetch(`api.php?action=update&id=${selectedMedia.value.id}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(selectedMedia.value)
                });
                const result = await response.json();
                if (result.status === 'success') {
                    // Update the local item
                    const index = items.value.findIndex(i => i.id === selectedMedia.value.id);
                    if (index !== -1) {
                        items.value[index].infos = selectedMedia.value.infos;
                    }
                    closeInfoModal();
                } else {
                    alert('Error saving info: ' + result.message);
                }
            } catch (error) {
                console.error('Error saving info:', error);
                alert('An error occurred while saving.');
            } finally {
                isSavingInfo.value = false;
            }
        };

        const handleFileUpload = (event) => {
            const file = event.target.files[0];
            if (file) {
                selectedFile.value = file;
                const reader = new FileReader();
                reader.onload = (e) => {
                    imagePreview.value = e.target.result;
                };
                reader.readAsDataURL(file);
            }
        };

        const openMediaModal = async (item = null) => {
            selectedFile.value = null;
            imagePreview.value = item?.image ? 'medias/' + item.image : null;
            if (item) {
                // Fetch full details of the media to get category_ids and rating_ids
                try {
                    const response = await fetch(`api.php?id=${item.id}`);
                    const result = await response.json();
                    if (result.status === 'success') {
                        const data = result.data;
                        mediaForm.value = {
                            id: data.id,
                            title: data.title,
                            image: data.image,
                            year: data.year,
                            score: parseFloat(data.score),
                            score_mal: parseFloat(data.score_mal),
                            status: data.status,
                            infos: data.infos,
                            category_id: data.category_id,
                            rating_id: data.rating_id
                        };
                    }
                } catch (error) {
                    console.error('Error fetching media details:', error);
                    // Fallback to basic data if fetch fails
                    mediaForm.value = {
                        ...item,
                        category_id: item.category_id || null,
                        rating_id: item.rating_id || null
                    };
                }
            } else {
                mediaForm.value = {
                    id: null,
                    title: '',
                    image: '',
                    year: String(new Date().getFullYear()),
                    score: 0,
                    score_mal: 0,
                    status: null,
                    infos: '',
                    category_id: null,
                    rating_id: null
                };
            }
            showMediaModal.value = true;
        };

        const closeMediaModal = () => {
            showMediaModal.value = false;
        };

        const saveMedia = async () => {
            isSavingMedia.value = true;
            try {
                const isUpdate = !!mediaForm.value.id;
                const url = isUpdate ? `api.php?id=${mediaForm.value.id}` : 'api.php';

                const formData = new FormData();
                formData.append('title', mediaForm.value.title);
                formData.append('year', mediaForm.value.year);
                formData.append('score', mediaForm.value.score);
                formData.append('score_mal', mediaForm.value.score_mal);
                formData.append('status', mediaForm.value.status || '');
                formData.append('infos', mediaForm.value.infos || '');
                formData.append('category_ids', mediaForm.value.category_id || '');
                formData.append('rating_ids', mediaForm.value.rating_id || '');

                if (selectedFile.value) {
                    formData.append('image_file', selectedFile.value);
                } else {
                    formData.append('image', mediaForm.value.image || '');
                }

                const response = await fetch(url, {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                if (result.status === 'success') {
                    closeMediaModal();
                    fetchMedia(); // Refresh the list
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (error) {
                console.error('Error saving media:', error);
                alert('An error occurred.');
            } finally {
                isSavingMedia.value = false;
            }
        };

        const deleteMedia = async (id) => {
            if (!confirm('Are you sure you want to delete this media?')) return;
            try {
                const response = await fetch(`api.php?id=${id}`, {
                    method: 'DELETE'
                });
                const result = await response.json();
                if (result.status === 'success') {
                    fetchMedia();
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (error) {
                console.error('Error deleting media:', error);
            }
        };

        onMounted(() => {
            applyTheme();
            fetchMedia();
            fetchFilters();
        });

        return {
            currentPage,
            pageSize,
            totalItems,
            totalPages,
            changePage,
            items,
            categories,
            ratings,
            searchQuery,
            selectedGenres,
            selectedStatuses,
            selectedRatings,
            loading,
            isSidebarOpen,
            searchActive,
            orderBy,
            isDarkMode,
            toggleSidebar,
            toggleTheme,
            toggleWatchStatus,
            updateScore,
            debounceSearch,
            fetchMedia,
            fetchRandomMedia,
            resetFilters,
            showInfoModal,
            showMediaModal,
            selectedMedia,
            mediaForm,
            isSavingInfo,
            isSavingMedia,
            openInfoModal,
            closeInfoModal,
            saveInfos,
            imagePreview,
            handleFileUpload,
            openMediaModal,
            closeMediaModal,
            saveMedia,
            deleteMedia
        };
    }
}).mount('#app');
