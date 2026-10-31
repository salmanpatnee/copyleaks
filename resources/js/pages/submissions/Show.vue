<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
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
import { Separator } from '@/components/ui/separator';
import { create, show } from '@/routes/submissions';

type SubmissionSummary = {
    public_id: string;
    original_name: string;
    created_at: string | null;
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
}>();

function formatSize(bytes: number): string {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function formatTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '';
}

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
                <Badge variant="secondary">
                    {{ submission.status.label }}
                </Badge>
            </div>
            <CardDescription>
                The file is stored safely. It has not been sent to Copyleaks.
            </CardDescription>
        </CardHeader>

        <CardContent class="flex flex-col gap-6">
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
