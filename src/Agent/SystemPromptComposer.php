<?php

namespace Hameleon2x\Llm\Agent;

use Hameleon2x\Llm\Dto\Message;
use Hameleon2x\Llm\Enum\Role;

/**
 * Сборка системного промта: базовый промт + блок пояснений по тулзам, уже вызванным в истории.
 * Используется Runner на каждом обороте цикла; отдельно — для показа промта в UI.
 */
class SystemPromptComposer
{
    /** Заголовок блока с пояснениями по уже использованным тулзам. */
    public const TOOL_NOTES_HEADER = 'Дополнительные пояснения по уже использованным инструментам:';

    /**
     * @param Message[] $history история диалога без system-сообщения
     */
    public function compose(string $basePrompt, array $history, ToolboxInterface $toolbox): string
    {
        $usedNames = $this->collectUsedToolNames($history);
        if ($usedNames === []) {
            return $basePrompt;
        }

        $additions = [];
        foreach ($usedNames as $name) {
            $desc = trim($toolbox->systemPromptAddition($name));
            if ($desc !== '') {
                $additions[] = $desc;
            }
        }

        if ($additions === []) {
            return $basePrompt;
        }

        return $basePrompt . "\n\n" . self::TOOL_NOTES_HEADER . "\n\n" . implode("\n\n", $additions);
    }

    /**
     * Уникальные имена тулз, вызванных ассистентом в истории (отсортированы по алфавиту).
     *
     * @param Message[] $history
     * @return string[]
     */
    private function collectUsedToolNames(array $history): array
    {
        $used = [];
        foreach ($history as $msg) {
            if (!$msg instanceof Message || $msg->role !== Role::ASSISTANT) {
                continue;
            }
            if (!is_array($msg->toolCalls) || $msg->toolCalls === []) {
                continue;
            }
            foreach ($msg->toolCalls as $toolCall) {
                if (!is_array($toolCall)) {
                    continue;
                }
                $name = $toolCall['function']['name'] ?? null;
                if (is_string($name) && $name !== '') {
                    $used[$name] = true;
                }
            }
        }

        $names = array_keys($used);
        sort($names);
        return $names;
    }
}
