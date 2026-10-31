<script setup lang="ts">
import { Head, Link, useForm, usePage, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { create, show } from '@/routes/submissions';
import { resend, store } from '@/routes/submissions/scans';

type SubmissionSummary = {
    public_id: string;
    original_name: string;
    created_at: string | null;
};

type ScanInfo = {
    status: string;
    label: string;
    failure_reason: string | null;
    ai_requested: boolean;
    submitted_at: string | null;
    can_resend: boolean;
    is_running: boolean;
    is_polling: boolean;
};

const props = defineProps<{
    submission: SubmissionSummary & {
        file_size: number;
        page_count: number | null;
        author: string;
        title: string;
        assignment: string;
        status: { value: string; label: string };
        is_word: boolean;
        duplicates: SubmissionSummary[];
    };
    scan: ScanInfo | null;
    can_submit: boolean;
    estimated_credits: number | null;
    scan_duplicate: SubmissionSummary | null;
}>();

const page = usePage();
const notice = computed(() => page.props.flash?.notice ?? null);
// A refused Submit or Resend comes back as an error on the `scan` key.
const scanError = computed(() => page.props.errors?.scan);

const submitForm = useForm({ include_ai: true, confirm_duplicate: false });
const resendForm = useForm({});

function submitScan(): void {
    submitForm.post(store.url(props.submission.public_id), {
        preserveScroll: true,
    });
}

function resendResult(): void {
    resendForm.post(resend.url(props.submission.public_id), {
        preserveScroll: true,
    });
}

// Refresh every 10 seconds while the answer is still on its way. Stops from "fetching" on.
const poll = usePoll(
    10_000,
    {
        only: [
            'submission',
            'scan',
            'can_submit',
            'estimated_credits',
            'scan_duplicate',
        ],
    },
    { autoStart: false },
);

watch(
    () => props.scan?.is_polling ?? false,
    (polling) => (polling ? poll.start() : poll.stop()),
    { immediate: true },
);

function formatSize(bytes: number): string {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function formatTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '';
}

const description = computed(() =>
    props.scan === null
        ? 'The file is stored safely. It has not been sent to Copyleaks.'
        : 'The file is stored safely.',
);

const statusHelp = computed(() => {
    switch (props.scan?.status) {
        case 'submitting':
            return 'Sending the file to Copyleaks. This page updates by itself.';
        case 'waiting':
            return 'Copyleaks is checking the file. This page updates by itself.';
        case 'fetching':
            return 'Copyleaks has finished. The result is being saved.';
        case 'completed':
            return 'The check is done.';
        default:
            return null;
    }
});

const rows = computed(() => [
    ['File name', props.submission.original_name],
    ['File size', formatSize(props.submission.file_size)],
    [
        'Pages',
        props.submission.page_count === null
            ? 'Unknown'
            : String(props.submission.page_count),
    ],
    ['Author', props.submission.author],
    ['Title', props.submission.title],
    ['Assignment', props.submission.assignment],
    ['Uploaded', formatTime(props.submission.created_at)],
]);
</script>

<template>
    <Head title="Manuscript saved" />

    <Card>
        <CardHeader>
            <div class="flex items-center justify-between gap-4">
                <CardTitle>Manuscript saved</CardTitle>
                <Badge variant="secondary" data-test="submission-status">
                    {{ submission.status.label }}
                </Badge>
            </div>
            <CardDescription>{{ description }}</CardDescription>
        </CardHeader>

        <CardContent class="flex flex-col gap-6">
            <Alert v-if="notice" data-test="notice">
                <AlertDescription>{{ notice }}</AlertDescription>
            </Alert>

            <Alert v-if="submission.is_word">
                <AlertTitle>Word files cannot be checked yet</AlertTitle>
                <AlertDescription>
                    This file is saved, but the check only works with PDF files
                    for now.
                </AlertDescription>
            </Alert>

            <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-3 text-sm">
                <template v-for="[label, value] in rows" :key="label">
                    <dt class="text-muted-foreground">{{ label }}</dt>
                    <dd class="break-words">{{ value }}</dd>
                </template>
            </dl>

            <template v-if="scan">
                <Separator />
                <section
                    class="flex flex-col gap-3"
                    aria-labelledby="scan-heading"
                    data-test="scan-status"
                >
                    <div class="flex items-center gap-3">
                        <h2 id="scan-heading" class="text-sm font-medium">
                            Copyleaks check
                        </h2>
                        <Badge
                            :variant="
                                scan.status === 'failed'
                                    ? 'destructive'
                                    : 'secondary'
                            "
                            data-test="scan-label"
                        >
                            <Spinner v-if="scan.is_polling" class="size-3" />
                            {{ scan.label }}
                        </Badge>
                    </div>

                    <p
                        v-if="statusHelp"
                        class="text-sm text-muted-foreground"
                        role="status"
                    >
                        {{ statusHelp }}
                    </p>

                    <Alert
                        v-if="scan.status === 'failed' && scan.failure_reason"
                        variant="destructive"
                        data-test="failure-reason"
                    >
                        <AlertTitle>The check failed</AlertTitle>
                        <AlertDescription>
                            {{ scan.failure_reason }}
                        </AlertDescription>
                    </Alert>

                    <div v-if="scan.can_resend" class="flex flex-col gap-2">
                        <p class="text-sm text-muted-foreground">
                            This is taking longer than expected. If Copyleaks
                            already finished, you can ask it to send the result
                            again. This does not start a new check.
                        </p>
                        <Button
                            type="button"
                            variant="outline"
                            class="self-start"
                            :disabled="resendForm.processing"
                            data-test="resend-button"
                            @click="resendResult"
                        >
                            <Spinner v-if="resendForm.processing" />
                            Resend result
                        </Button>
                        <InputError :message="scanError" />
                    </div>
                </section>
            </template>

            <template v-if="can_submit">
                <Separator />
                <form
                    class="flex flex-col gap-4"
                    data-test="submit-panel"
                    @submit.prevent="submitScan"
                >
                    <h2 class="text-sm font-medium">Check with Copyleaks</h2>

                    <p
                        v-if="estimated_credits !== null"
                        class="text-sm text-muted-foreground"
                        data-test="credit-estimate"
                    >
                        This check will use about
                        {{ estimated_credits }}
                        {{ estimated_credits === 1 ? 'credit' : 'credits' }}. It
                        is a guess and can be different.
                    </p>

                    <Label for="include_ai" class="flex items-center gap-3">
                        <Checkbox
                            id="include_ai"
                            :model-value="submitForm.include_ai"
                            @update:model-value="
                                submitForm.include_ai = $event === true
                            "
                        />
                        <span>Also check for AI written text</span>
                    </Label>

                    <Alert v-if="scan_duplicate" data-test="scan-duplicate">
                        <AlertTitle>This file was already checked</AlertTitle>
                        <AlertDescription class="flex flex-col gap-3">
                            <span>
                                Same file as
                                <Link
                                    :href="show(scan_duplicate.public_id)"
                                    class="underline underline-offset-4"
                                >
                                    {{ scan_duplicate.original_name }}
                                </Link>
                                ({{ formatTime(scan_duplicate.created_at) }}).
                                Checking it again uses credits.
                            </span>
                            <Label
                                for="confirm_duplicate"
                                class="flex items-center gap-3"
                            >
                                <Checkbox
                                    id="confirm_duplicate"
                                    :model-value="submitForm.confirm_duplicate"
                                    @update:model-value="
                                        submitForm.confirm_duplicate =
                                            $event === true
                                    "
                                />
                                <span>Check it again anyway</span>
                            </Label>
                        </AlertDescription>
                    </Alert>

                    <InputError :message="scanError" />

                    <Button
                        type="submit"
                        class="self-start"
                        :disabled="submitForm.processing"
                        data-test="submit-scan-button"
                    >
                        <Spinner v-if="submitForm.processing" />
                        Submit for checking
                    </Button>
                </form>
            </template>

            <template v-if="submission.duplicates.length > 0">
                <Separator />
                <Alert>
                    <AlertTitle>This file was uploaded before</AlertTitle>
                    <AlertDescription>
                        <ul class="flex flex-col gap-1">
                            <li
                                v-for="duplicate in submission.duplicates"
                                :key="duplicate.public_id"
                            >
                                <Link
                                    :href="show(duplicate.public_id)"
                                    class="underline underline-offset-4"
                                >
                                    {{ duplicate.original_name }}
                                </Link>
                                <span class="text-muted-foreground">
                                    ({{ formatTime(duplicate.created_at) }})
                                </span>
                            </li>
                        </ul>
                    </AlertDescription>
                </Alert>
            </template>
        </CardContent>

        <CardFooter>
            <Button as-child variant="outline">
                <Link :href="create()">Upload another</Link>
            </Button>
        </CardFooter>
    </Card>
</template>
