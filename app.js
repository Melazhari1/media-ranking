// MMR — root app: router, header, global modals.
// Internal modules are imported with the same ?v= everywhere (one instance each); bump it on deploy.
import { Icon, ConfirmHost, ToastHost } from './js/components/common.js?v=2.1.0';
import { MediaFormModal } from './js/components/forms.js?v=2.1.0';
import { ImportExportModal, PickOneModal, AddToListModal } from './js/components/modals.js?v=2.1.0';
import { SettingsModal } from './js/components/settings.js?v=2.1.0';
import { HomeView } from './js/views/HomeView.js?v=2.1.0';
import { DetailView } from './js/views/DetailView.js?v=2.1.0';
import { StatsView } from './js/views/StatsView.js?v=2.1.0';
import { DiaryView } from './js/views/DiaryView.js?v=2.1.0';
import { ListsView } from './js/views/ListsView.js?v=2.1.0';
import { prefs, filters, modals, ui, openForm, loadLookups, showToast, mediaVersion } from './js/store.js?v=2.1.0';
import { mediaPath } from './js/format.js?v=2.1.0';

const { createApp, ref, watch } = Vue;
const { createRouter, createWebHistory } = VueRouter;

// <base href> is set by index.php to the install directory ("/" in production, "/hostinger/mediarank/" locally).
const basePath = new URL(document.baseURI).pathname;

const router = createRouter({
    history: createWebHistory(basePath),
    routes: [
        { path: '/', name: 'home', component: HomeView },
        { path: '/media/:ref', name: 'media', component: DetailView },
        { path: '/stats', name: 'stats', component: StatsView },
        { path: '/diary', name: 'diary', component: DiaryView },
        { path: '/lists', name: 'lists', component: ListsView },
        { path: '/index.html', redirect: '/' },
        { path: '/index.php', redirect: '/' },
        { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
    scrollBehavior: (to, from, saved) => saved || (to.path !== from.path ? { top: 0 } : undefined),
});

router.afterEach((to) => {
    ui.sidebarOpen = false;
    if (to.name === 'home') document.title = 'MMR - My Media Ranking';
});

const App = {
    components: { Icon, ConfirmHost, ToastHost, MediaFormModal, ImportExportModal, PickOneModal, AddToListModal, SettingsModal },
    setup() {
        const route = VueRouter.useRoute();
        const search = ref(filters.keyword);
        let t = null;
        // Header search drives the home filters; from another page it navigates home.
        watch(search, (q) => {
            clearTimeout(t);
            t = setTimeout(() => {
                filters.keyword = q;
                if (route.name !== 'home' && q.trim()) router.push({ path: '/', query: { q: q.trim() } });
            }, 300);
        });
        watch(() => filters.keyword, (k) => { if (k !== search.value) search.value = k; });

        const onFormClose = (newId) => {
            const wasAdd = modals.form && !modals.form.item;
            modals.form = null;
            if (wasAdd && newId) {
                showToast('Added — open it?', { label: 'Open', run: () => router.push(mediaPath({ id: newId, title: '' })) }, 6000);
            }
        };
        return { prefs, modals, ui, search, route, openForm, onFormClose, mediaVersion };
    },
    template: `
    <a class="skip-link" href="#main-content">Skip to content</a>
    <header>
        <div class="header-left">
            <button v-if="route.name === 'home'" class="menu-btn" @click="ui.sidebarOpen = !ui.sidebarOpen" :aria-expanded="ui.sidebarOpen" aria-label="Filters">☰</button>
            <router-link to="/" class="logo" aria-label="MMR home">MMR</router-link>
            <nav class="main-nav" aria-label="Main">
                <router-link to="/" exact-active-class="active"><Icon name="home" :size="16" /><span>Home</span></router-link>
                <router-link to="/stats" active-class="active"><Icon name="chart" :size="16" /><span>Stats</span></router-link>
                <router-link to="/diary" active-class="active"><Icon name="calendar" :size="16" /><span>Diary</span></router-link>
                <router-link to="/lists" active-class="active"><Icon name="bookmark" :size="16" /><span>Lists</span></router-link>
            </nav>
        </div>

        <div class="search-container">
            <label for="global-search" class="sr-only">Search</label>
            <input id="global-search" type="search" v-model="search" placeholder="Search anime or genre...">
        </div>

        <div class="user-actions">
            <button class="btn-add-header" @click="openForm()" title="Add New Media">
                <span class="btn-add-header-icon">+</span><span class="btn-add-header-label"> Add New Media</span>
            </button>
            <button class="theme-toggle" @click="modals.settings = true" title="Settings" aria-label="Settings"><Icon name="settings" :size="20" /></button>
            <button class="theme-toggle" @click="prefs.dark = !prefs.dark" :title="prefs.dark ? 'Switch to light mode' : 'Switch to dark mode'" :aria-label="prefs.dark ? 'Switch to light mode' : 'Switch to dark mode'">
                <svg v-if="prefs.dark" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="5" /><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42" />
                </svg>
                <svg v-else width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
                </svg>
            </button>
        </div>
    </header>

    <div id="main-content" class="app-body" tabindex="-1">
        <router-view />
    </div>

    <MediaFormModal v-if="modals.form" :item="modals.form.item" @close="onFormClose" />
    <ImportExportModal v-if="modals.importExport" @close="modals.importExport = false" />
    <PickOneModal v-if="modals.pickOne" @close="modals.pickOne = false" />
    <SettingsModal v-if="modals.settings" @close="modals.settings = false" />
    <AddToListModal v-if="ui.addToList" :items="ui.addToList" @close="ui.addToList = null" />
    <ConfirmHost />
    <ToastHost />`,
};

const app = createApp(App);
app.use(router);
app.config.errorHandler = (err) => {
    console.error(err);
    showToast('Something went wrong: ' + (err?.message || err));
};
loadLookups().catch((e) => showToast('Could not load filters: ' + e.message));
app.mount('#app');
