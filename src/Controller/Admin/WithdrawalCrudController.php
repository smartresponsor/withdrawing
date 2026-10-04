<?php

declare(strict_types=1);

namespace App\Withdrawing\Controller\Admin;

use App\Withdrawing\Entity\Withdrawal;
use App\Withdrawing\Enum\WithdrawalStatus;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Provides the EasyAdmin back-office field projection for withdrawal records.
 *
 * @extends AbstractCrudController<Withdrawal>
 */
final class WithdrawalCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Withdrawal::class;
    }

    /**
     * Configure the back-office field projection for withdrawal lifecycle records.
     */
    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('sourceType');
        yield TextField::new('sourceId');
        yield TextField::new('actorType');
        yield TextField::new('actorId');
        yield IntegerField::new('amountMinor');
        yield TextField::new('currency');
        yield ChoiceField::new('status')->setChoices(array_combine(
            array_map(static fn (WithdrawalStatus $case): string => $case->value, WithdrawalStatus::cases()),
            WithdrawalStatus::cases(),
        ));
        yield TextField::new('destinationReference')->hideOnIndex();
        yield TextField::new('railReference')->hideOnIndex();
    }
}
