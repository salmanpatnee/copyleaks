<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/submissions';

const MAX_BYTES = 20 * 1024 * 1024;

defineProps<{
    notice: string | null;
}>();

const tooBigMessage = 'The file is too big. The limit is 20 MB.';
const sizeError = ref<string | null>(null);

function checkSize(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];

    sizeError.value = file && file.size > MAX_BYTES ? tooBigMessage : null;
}
</script>

<template>
    <Head title="Upload a manuscript" />

    <Card>
        <CardHeader>
            <CardTitle>Upload a manuscript</CardTitle>
            <CardDescription>
                PDF or Word (.docx), up to 20 MB. Uploading is free. Nothing is
                sent to Copyleaks from this page.
            </CardDescription>
        </CardHeader>

        <Form
            v-bind="store.form()"
            v-slot="{ errors, processing, progress }"
            :disable-while-processing="false"
            @before="sizeError ? false : undefined"
        >
            <CardContent>
                <FieldGroup>
                    <Field
                        :data-invalid="!!(errors.file || sizeError || notice)"
                    >
                        <FieldLabel for="file">Manuscript file</FieldLabel>
                        <Input
                            id="file"
                            type="file"
                            name="file"
                            accept=".pdf,.docx"
                            required
                            :aria-invalid="
                                !!(errors.file || sizeError || notice)
                            "
                            @change="checkSize"
                        />
                        <InputError
                            :message="
                                sizeError ?? errors.file ?? notice ?? undefined
                            "
                        />
                    </Field>

                    <Field :data-invalid="!!errors.author">
                        <FieldLabel for="author">Author</FieldLabel>
                        <Input
                            id="author"
                            name="author"
                            required
                            maxlength="255"
                            autocomplete="off"
                            :aria-invalid="!!errors.author"
                        />
                        <InputError :message="errors.author" />
                    </Field>

                    <Field :data-invalid="!!errors.title">
                        <FieldLabel for="title">Title</FieldLabel>
                        <Input
                            id="title"
                            name="title"
                            required
                            maxlength="255"
                            autocomplete="off"
                            :aria-invalid="!!errors.title"
                        />
                        <InputError :message="errors.title" />
                    </Field>

                    <Field :data-invalid="!!errors.assignment">
                        <FieldLabel for="assignment">
                            Assignment or class name
                        </FieldLabel>
                        <Input
                            id="assignment"
                            name="assignment"
                            required
                            maxlength="255"
                            autocomplete="off"
                            :aria-invalid="!!errors.assignment"
                        />
                        <InputError :message="errors.assignment" />
                    </Field>

                    <Progress
                        v-if="processing && progress"
                        :model-value="progress.percentage"
                    />
                </FieldGroup>
            </CardContent>

            <CardFooter class="mt-6">
                <Button
                    type="submit"
                    class="w-full"
                    :disabled="processing || !!sizeError"
                    data-test="upload-button"
                >
                    <Spinner v-if="processing" />
                    Upload
                </Button>
            </CardFooter>
        </Form>
    </Card>
</template>
