<script setup lang="ts">
    import AdminLayout from '@/layouts/AdminLayout.vue';
    import { Head, usePage } from '@inertiajs/vue3';

    interface Props {
        welcomeMessage?: string | null;
    }

    withDefaults(defineProps<Props>(), {
        welcomeMessage: null,
    });

    const page = usePage();
    const user = computed(() => page.props.auth?.user);

    const currentDate = new Date().toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });

    type Trend = 'up' | 'down';

    interface Kpi {
        label: string;
        value: string;
        delta: string;
        trend: Trend;
        icon: string;
        accent: string;
        spark: number[];
    }

    const kpis: Kpi[] = [
        {
            label: 'Total Revenue',
            value: '$284,650',
            delta: '+12.4%',
            trend: 'up',
            icon: 'pi pi-wallet',
            accent: 'emerald',
            spark: [22, 28, 26, 32, 30, 38, 44, 42, 48, 52, 50, 58],
        },
        {
            label: 'Active Users',
            value: '3,842',
            delta: '+8.1%',
            trend: 'up',
            icon: 'pi pi-users',
            accent: 'sky',
            spark: [40, 42, 48, 45, 52, 55, 58, 62, 60, 68, 72, 78],
        },
        {
            label: 'New Orders',
            value: '186',
            delta: '-3.2%',
            trend: 'down',
            icon: 'pi pi-shopping-cart',
            accent: 'amber',
            spark: [60, 58, 62, 55, 50, 52, 48, 52, 46, 42, 40, 44],
        },
        {
            label: 'Conversion Rate',
            value: '4.68%',
            delta: '+1.9%',
            trend: 'up',
            icon: 'pi pi-chart-line',
            accent: 'violet',
            spark: [30, 32, 28, 34, 36, 40, 38, 44, 48, 46, 50, 54],
        },
    ];

    const revenueMonths = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const revenueCurrent = [38, 52, 45, 62, 58, 72, 68, 84, 78, 92, 88, 104];
    const revenuePrev = [28, 36, 42, 48, 44, 54, 58, 62, 66, 70, 74, 80];

    const categories = [
        { label: 'Software', value: 42, color: '#6366f1' },
        { label: 'Hardware', value: 26, color: '#10b981' },
        { label: 'Service', value: 18, color: '#f59e0b' },
        { label: 'Consulting', value: 14, color: '#ef4444' },
    ];

    const categoryTotal = categories.reduce((sum, c) => sum + c.value, 0);

    interface Order {
        id: string;
        customer: string;
        email: string;
        product: string;
        amount: string;
        status: 'completed' | 'pending' | 'failed';
        date: string;
    }

    const recentOrders: Order[] = [
        {
            id: '#ORD-4821',
            customer: 'Alice Smith',
            email: 'alice@example.com',
            product: 'Pro Plan',
            amount: '$2,499',
            status: 'completed',
            date: '2 min ago',
        },
        {
            id: '#ORD-4820',
            customer: 'Michael Johnson',
            email: 'michael@example.com',
            product: 'Enterprise',
            amount: '$8,900',
            status: 'completed',
            date: '12 min ago',
        },
        {
            id: '#ORD-4819',
            customer: 'Sarah Williams',
            email: 'sarah@example.com',
            product: 'Starter',
            amount: '$499',
            status: 'pending',
            date: '38 min ago',
        },
        {
            id: '#ORD-4818',
            customer: 'David Brown',
            email: 'david@example.com',
            product: 'Pro Plan',
            amount: '$2,499',
            status: 'completed',
            date: '1 hr ago',
        },
        {
            id: '#ORD-4817',
            customer: 'Emma Davis',
            email: 'emma@example.com',
            product: 'Addon Pack',
            amount: '$349',
            status: 'failed',
            date: '2 hr ago',
        },
        {
            id: '#ORD-4816',
            customer: 'Chris Wilson',
            email: 'chris@example.com',
            product: 'Enterprise',
            amount: '$8,900',
            status: 'completed',
            date: '3 hr ago',
        },
    ];

    const statusMap: Record<Order['status'], { label: string; severity: 'success' | 'warn' | 'danger' }> = {
        completed: { label: 'Completed', severity: 'success' },
        pending: { label: 'Pending', severity: 'warn' },
        failed: { label: 'Failed', severity: 'danger' },
    };

    interface Activity {
        user: string;
        action: string;
        target: string;
        time: string;
        icon: string;
        color: string;
    }

    const activities: Activity[] = [
        {
            user: 'Alice S.',
            action: 'placed a new order',
            target: '#ORD-4821',
            time: '2 min',
            icon: 'pi pi-shopping-bag',
            color: 'bg-emerald-500',
        },
        {
            user: 'Michael J.',
            action: 'made a payment of',
            target: '$8,900',
            time: '12 min',
            icon: 'pi pi-credit-card',
            color: 'bg-sky-500',
        },
        {
            user: 'Sarah W.',
            action: 'created an account',
            target: '',
            time: '38 min',
            icon: 'pi pi-user-plus',
            color: 'bg-violet-500',
        },
        {
            user: 'David B.',
            action: 'upgraded plan',
            target: 'Pro → Enterprise',
            time: '1 hr',
            icon: 'pi pi-arrow-circle-up',
            color: 'bg-amber-500',
        },
        {
            user: 'Emma D.',
            action: 'contacted support',
            target: '#TCK-1204',
            time: '2 hr',
            icon: 'pi pi-comments',
            color: 'bg-rose-500',
        },
    ];

    interface TopCustomer {
        name: string;
        email: string;
        total: string;
        orders: number;
        avatar: string;
    }

    const topCustomers: TopCustomer[] = [
        { name: 'Michael Johnson', email: 'michael@example.com', total: '$42,800', orders: 18, avatar: 'MJ' },
        { name: 'Alice Smith', email: 'alice@example.com', total: '$28,450', orders: 12, avatar: 'AS' },
        { name: 'Chris Wilson', email: 'chris@example.com', total: '$19,200', orders: 9, avatar: 'CW' },
        { name: 'David Brown', email: 'david@example.com', total: '$14,600', orders: 7, avatar: 'DB' },
    ];

    function sparkPath(points: number[], width = 120, height = 40): string {
        const max = Math.max(...points);
        const min = Math.min(...points);
        const range = max - min || 1;
        const step = width / (points.length - 1);
        return points
            .map((p, i) => {
                const x = i * step;
                const y = height - ((p - min) / range) * height;
                return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`;
            })
            .join(' ');
    }

    function sparkArea(points: number[], width = 120, height = 40): string {
        return `${sparkPath(points, width, height)} L${width},${height} L0,${height} Z`;
    }

    const accentClass: Record<string, { text: string; bg: string; stroke: string; fill: string }> = {
        emerald: {
            text: 'text-emerald-600 dark:text-emerald-400',
            bg: 'bg-emerald-50 dark:bg-emerald-500/10',
            stroke: 'stroke-emerald-500',
            fill: 'fill-emerald-500/20',
        },
        sky: {
            text: 'text-sky-600 dark:text-sky-400',
            bg: 'bg-sky-50 dark:bg-sky-500/10',
            stroke: 'stroke-sky-500',
            fill: 'fill-sky-500/20',
        },
        amber: {
            text: 'text-amber-600 dark:text-amber-400',
            bg: 'bg-amber-50 dark:bg-amber-500/10',
            stroke: 'stroke-amber-500',
            fill: 'fill-amber-500/20',
        },
        violet: {
            text: 'text-violet-600 dark:text-violet-400',
            bg: 'bg-violet-50 dark:bg-violet-500/10',
            stroke: 'stroke-violet-500',
            fill: 'fill-violet-500/20',
        },
    };

    function polarPoint(cx: number, cy: number, r: number, angle: number) {
        const rad = ((angle - 90) * Math.PI) / 180;
        return { x: cx + r * Math.cos(rad), y: cy + r * Math.sin(rad) };
    }

    function donutSegment(startAngle: number, endAngle: number, cx = 80, cy = 80, r = 64, innerR = 44) {
        const largeArc = endAngle - startAngle > 180 ? 1 : 0;
        const outerStart = polarPoint(cx, cy, r, startAngle);
        const outerEnd = polarPoint(cx, cy, r, endAngle);
        const innerStart = polarPoint(cx, cy, innerR, endAngle);
        const innerEnd = polarPoint(cx, cy, innerR, startAngle);
        return [
            `M${outerStart.x.toFixed(2)},${outerStart.y.toFixed(2)}`,
            `A${r},${r} 0 ${largeArc} 1 ${outerEnd.x.toFixed(2)},${outerEnd.y.toFixed(2)}`,
            `L${innerStart.x.toFixed(2)},${innerStart.y.toFixed(2)}`,
            `A${innerR},${innerR} 0 ${largeArc} 0 ${innerEnd.x.toFixed(2)},${innerEnd.y.toFixed(2)}`,
            'Z',
        ].join(' ');
    }

    const donutSegments = computed(() => {
        let acc = 0;
        return categories.map((c) => {
            const startAngle = (acc / categoryTotal) * 360;
            acc += c.value;
            const endAngle = (acc / categoryTotal) * 360;
            return {
                ...c,
                path: donutSegment(startAngle, endAngle),
                percent: Math.round((c.value / categoryTotal) * 100),
            };
        });
    });

    const revenueMax = Math.max(...revenueCurrent, ...revenuePrev);

    function chartPath(points: number[], width = 600, height = 200): string {
        const step = width / (points.length - 1);
        return points
            .map((p, i) => {
                const x = i * step;
                const y = height - (p / revenueMax) * (height - 20) - 10;
                return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`;
            })
            .join(' ');
    }

    function chartArea(points: number[], width = 600, height = 200): string {
        return `${chartPath(points, width, height)} L${width},${height} L0,${height} Z`;
    }
</script>

<template>
    <Head title="Dashboard" />

    <AdminLayout>
        <div class="w-full space-y-6">
            <!-- Header -->
            <header class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-surface-900 dark:text-surface-0">
                        Welcome, {{ user?.first_name || 'Admin' }}
                    </h1>
                    <p class="mt-1 text-sm text-surface-500 dark:text-surface-400">
                        {{ currentDate }} — here's today's summary.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Button label="Export" icon="pi pi-download" severity="secondary" outlined size="small" />
                    <Button label="New Report" icon="pi pi-plus" size="small" />
                </div>
            </header>

            <!-- Welcome Message (from Settings > General) -->
            <section
                v-if="welcomeMessage"
                class="rounded-xl border border-surface-200 bg-surface-0 p-5 dark:border-surface-700 dark:bg-surface-900"
            >
                <div class="sk-prose" v-html="welcomeMessage" />
            </section>

            <!-- KPI Grid -->
            <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div
                    v-for="kpi in kpis"
                    :key="kpi.label"
                    class="relative overflow-hidden rounded-xl border border-surface-200 bg-surface-0 p-5 transition hover:shadow-md dark:border-surface-700 dark:bg-surface-900"
                >
                    <div class="flex items-start justify-between">
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-lg"
                            :class="accentClass[kpi.accent].bg"
                        >
                            <i :class="[kpi.icon, accentClass[kpi.accent].text]" class="text-lg" />
                        </div>
                        <span
                            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="
                                kpi.trend === 'up'
                                    ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400'
                                    : 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400'
                            "
                        >
                            <i
                                :class="kpi.trend === 'up' ? 'pi pi-arrow-up' : 'pi pi-arrow-down'"
                                class="text-[10px]"
                            />
                            {{ kpi.delta }}
                        </span>
                    </div>
                    <div class="mt-4">
                        <p class="text-sm text-surface-500 dark:text-surface-400">
                            {{ kpi.label }}
                        </p>
                        <p class="mt-1 text-2xl font-bold text-surface-900 dark:text-surface-0">
                            {{ kpi.value }}
                        </p>
                    </div>
                    <svg viewBox="0 0 120 40" class="mt-3 h-10 w-full" preserveAspectRatio="none">
                        <path :d="sparkArea(kpi.spark)" :class="accentClass[kpi.accent].fill" stroke="none" />
                        <path
                            :d="sparkPath(kpi.spark)"
                            :class="accentClass[kpi.accent].stroke"
                            stroke-width="2"
                            fill="none"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </div>
            </section>

            <!-- Revenue + Category -->
            <section class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div
                    class="rounded-xl border border-surface-200 bg-surface-0 p-5 lg:col-span-2 dark:border-surface-700 dark:bg-surface-900"
                >
                    <div class="flex items-start justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-0">
                                Revenue Trend
                            </h2>
                            <p class="mt-1 text-sm text-surface-500 dark:text-surface-400">
                                Last 12 months — compared to previous year
                            </p>
                        </div>
                        <div class="flex items-center gap-4 text-xs">
                            <span class="flex items-center gap-1.5 text-surface-600 dark:text-surface-300">
                                <span class="h-2 w-2 rounded-full bg-indigo-500" /> This year
                            </span>
                            <span class="flex items-center gap-1.5 text-surface-600 dark:text-surface-300">
                                <span class="h-2 w-2 rounded-full bg-surface-300 dark:bg-surface-600" /> Last year
                            </span>
                        </div>
                    </div>

                    <div class="mt-4">
                        <svg viewBox="0 0 600 220" class="h-56 w-full" preserveAspectRatio="none">
                            <defs>
                                <linearGradient id="revFill" x1="0" x2="0" y1="0" y2="1">
                                    <stop offset="0%" stop-color="#6366f1" stop-opacity="0.35" />
                                    <stop offset="100%" stop-color="#6366f1" stop-opacity="0" />
                                </linearGradient>
                            </defs>
                            <g class="text-surface-200 dark:text-surface-700">
                                <line
                                    v-for="i in 4"
                                    :key="i"
                                    :x1="0"
                                    :x2="600"
                                    :y1="i * 50"
                                    :y2="i * 50"
                                    stroke="currentColor"
                                    stroke-dasharray="3 4"
                                    stroke-width="1"
                                />
                            </g>
                            <path :d="chartArea(revenueCurrent)" fill="url(#revFill)" />
                            <path
                                :d="chartPath(revenuePrev)"
                                stroke="currentColor"
                                class="text-surface-300 dark:text-surface-600"
                                stroke-width="2"
                                fill="none"
                                stroke-dasharray="4 4"
                                stroke-linecap="round"
                            />
                            <path
                                :d="chartPath(revenueCurrent)"
                                stroke="#6366f1"
                                stroke-width="2.5"
                                fill="none"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <div class="mt-2 flex justify-between text-xs text-surface-500 dark:text-surface-400">
                            <span v-for="m in revenueMonths" :key="m">{{ m }}</span>
                        </div>
                    </div>
                </div>

                <div
                    class="rounded-xl border border-surface-200 bg-surface-0 p-5 dark:border-surface-700 dark:bg-surface-900"
                >
                    <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-0">
                        Category Distribution
                    </h2>
                    <p class="mt-1 text-sm text-surface-500 dark:text-surface-400">
                        This quarter
                    </p>

                    <div class="mt-4 flex items-center justify-center">
                        <div class="relative">
                            <svg viewBox="0 0 160 160" class="h-40 w-40">
                                <path v-for="seg in donutSegments" :key="seg.label" :d="seg.path" :fill="seg.color" />
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <span class="text-xs text-surface-500 dark:text-surface-400">Total</span>
                                <span class="text-xl font-bold text-surface-900 dark:text-surface-0">
                                    {{ categoryTotal }}K
                                </span>
                            </div>
                        </div>
                    </div>

                    <ul class="mt-4 space-y-2">
                        <li
                            v-for="seg in donutSegments"
                            :key="seg.label"
                            class="flex items-center justify-between text-sm"
                        >
                            <span class="flex items-center gap-2 text-surface-700 dark:text-surface-300">
                                <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: seg.color }" />
                                {{ seg.label }}
                            </span>
                            <span class="font-medium text-surface-900 dark:text-surface-0">{{ seg.percent }}%</span>
                        </li>
                    </ul>
                </div>
            </section>

            <!-- Orders + Activity -->
            <section class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div
                    class="rounded-xl border border-surface-200 bg-surface-0 lg:col-span-2 dark:border-surface-700 dark:bg-surface-900"
                >
                    <div
                        class="flex items-center justify-between border-b border-surface-200 p-5 dark:border-surface-700"
                    >
                        <div>
                            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-0">
                                Recent Orders
                            </h2>
                            <p class="mt-1 text-sm text-surface-500 dark:text-surface-400">
                                Last 24 hours
                            </p>
                        </div>
                        <Button label="View All" icon="pi pi-arrow-right" icon-pos="right" text size="small" />
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-surface-50 dark:bg-surface-800/50">
                                <tr
                                    class="text-left text-xs uppercase tracking-wider text-surface-500 dark:text-surface-400"
                                >
                                    <th class="px-5 py-3 font-medium">
                                        Order
                                    </th>
                                    <th class="px-5 py-3 font-medium">
                                        Customer
                                    </th>
                                    <th class="px-5 py-3 font-medium">
                                        Product
                                    </th>
                                    <th class="px-5 py-3 font-medium">
                                        Amount
                                    </th>
                                    <th class="px-5 py-3 font-medium">
                                        Status
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-surface-200 dark:divide-surface-700">
                                <tr
                                    v-for="order in recentOrders"
                                    :key="order.id"
                                    class="hover:bg-surface-50 dark:hover:bg-surface-800/30"
                                >
                                    <td class="px-5 py-3 font-mono text-xs text-surface-600 dark:text-surface-400">
                                        {{ order.id }}
                                    </td>
                                    <td class="px-5 py-3">
                                        <div class="font-medium text-surface-900 dark:text-surface-0">
                                            {{ order.customer }}
                                        </div>
                                        <div class="text-xs text-surface-500 dark:text-surface-400">
                                            {{ order.date }}
                                        </div>
                                    </td>
                                    <td class="px-5 py-3 text-surface-700 dark:text-surface-300">
                                        {{ order.product }}
                                    </td>
                                    <td class="px-5 py-3 font-semibold text-surface-900 dark:text-surface-0">
                                        {{ order.amount }}
                                    </td>
                                    <td class="px-5 py-3">
                                        <Tag
                                            :value="statusMap[order.status].label"
                                            :severity="statusMap[order.status].severity"
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div
                    class="rounded-xl border border-surface-200 bg-surface-0 dark:border-surface-700 dark:bg-surface-900"
                >
                    <div class="border-b border-surface-200 p-5 dark:border-surface-700">
                        <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-0">
                            Activity Feed
                        </h2>
                        <p class="mt-1 text-sm text-surface-500 dark:text-surface-400">
                            Live updates
                        </p>
                    </div>

                    <ul class="space-y-4 p-5">
                        <li v-for="(a, i) in activities" :key="i" class="flex gap-3">
                            <div
                                class="flex h-8 w-8 flex-none items-center justify-center rounded-full text-white"
                                :class="a.color"
                            >
                                <i :class="a.icon" class="text-sm" />
                            </div>
                            <div class="flex-1">
                                <p class="text-sm text-surface-800 dark:text-surface-200">
                                    <span class="font-medium">{{ a.user }}</span>
                                    {{ a.action }}
                                    <span v-if="a.target" class="font-medium">{{ a.target }}</span>
                                </p>
                                <p class="mt-0.5 text-xs text-surface-500 dark:text-surface-400">
                                    {{ a.time }} ago
                                </p>
                            </div>
                        </li>
                    </ul>
                </div>
            </section>

            <!-- Top Customers -->
            <section
                class="rounded-xl border border-surface-200 bg-surface-0 dark:border-surface-700 dark:bg-surface-900"
            >
                <div class="flex items-center justify-between border-b border-surface-200 p-5 dark:border-surface-700">
                    <div>
                        <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-0">
                            Top Customers
                        </h2>
                        <p class="mt-1 text-sm text-surface-500 dark:text-surface-400">
                            Highest spenders
                        </p>
                    </div>
                    <Button label="View Report" icon="pi pi-external-link" icon-pos="right" text size="small" />
                </div>

                <div class="grid grid-cols-1 gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="(c, i) in topCustomers"
                        :key="c.email"
                        class="flex items-center gap-3 rounded-lg border border-surface-200 p-4 transition hover:border-primary-400 dark:border-surface-700 dark:hover:border-primary-500"
                    >
                        <div class="relative">
                            <div
                                class="flex h-11 w-11 items-center justify-center rounded-full bg-linear-to-br from-primary-500 to-violet-500 text-sm font-semibold text-white"
                            >
                                {{ c.avatar }}
                            </div>
                            <span
                                class="absolute -right-1 -top-1 flex h-5 w-5 items-center justify-center rounded-full bg-amber-400 text-xs font-bold text-white ring-2 ring-surface-0 dark:ring-surface-900"
                            >
                                {{ i + 1 }}
                            </span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-surface-900 dark:text-surface-0">
                                {{ c.name }}
                            </p>
                            <p class="truncate text-xs text-surface-500 dark:text-surface-400">
                                {{ c.orders }} orders · {{ c.total }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
