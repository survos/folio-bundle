<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Survos\FolioBundle\Model\FolioContext;
use Survos\FolioBundle\Service\FolioChatContextHolder;
use Survos\FolioBundle\Service\FolioChatService;
use Survos\FolioBundle\Service\FolioQueryAnalyzer;
use Survos\FolioBundle\Service\FolioRetriever;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\ExecutionInterface;

final class FolioChatServiceTest extends TestCase
{
    #[DataProvider('outcomes')]
    public function testLazyExecutionKeepsToolContextAndClearsItAfterwards(bool $fail): void
    {
        $holder = new FolioChatContextHolder();
        $context = new FolioContext('test', '/unused.rend', $this->createStub(EntityManagerInterface::class));
        $execution = $this->createMock(ExecutionInterface::class);
        $execution->expects(self::once())->method('getContent')->willReturnCallback(function () use ($holder, $context, $fail): string {
            self::assertSame($context, $holder->require());
            if ($fail) {
                throw new \RuntimeException('Provider unavailable');
            }

            return 'A supported answer';
        });
        $agent = $this->createStub(AgentInterface::class);
        $agent->method('call')->willReturn($execution);
        $service = new FolioChatService(new FolioRetriever(new FolioQueryAnalyzer()), $holder, $agent);
        $answer = $service->askScoped($context, 'What is shown?', 'page', []);
        self::assertNull($holder->get());
        if ($fail) {
            self::assertStringContainsString('Provider unavailable', $answer->error);
        } else {
            self::assertSame('A supported answer', $answer->answer);
            self::assertNull($answer->error);
        }
    }

    public static function outcomes(): iterable
    {
        yield 'success' => [false];
        yield 'failure' => [true];
    }
}
