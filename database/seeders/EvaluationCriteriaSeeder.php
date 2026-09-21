<?php

namespace Database\Seeders;

use App\Models\EvaluationCriteria;
use Illuminate\Database\Seeder;

class EvaluationCriteriaSeeder extends Seeder
{
    /**
     * Sample evaluation form: a starter set of criteria grouped by
     * category. Criteria live as rows in evaluation_criteria (not columns),
     * so this list can be expanded/edited/deactivated later via the
     * Evaluation Criteria admin page without any schema change.
     */
    public function run(): void
    {
        $categories = [
            'Attendance & Punctuality' => [
                'Reports to work on time and follows the agreed schedule.',
                'Maintains consistent attendance with minimal unexcused absences.',
            ],
            'Work Quality' => [
                'Produces work that meets the required standard of accuracy and completeness.',
                'Pays attention to detail and avoids repeated errors.',
            ],
            'Productivity' => [
                'Completes assigned tasks within expected timeframes.',
                'Makes effective use of work hours.',
            ],
            'Communication' => [
                'Communicates clearly and professionally with supervisors and staff.',
                'Listens well and asks clarifying questions when needed.',
            ],
            'Teamwork' => [
                'Works well with others and contributes positively to team goals.',
                'Willing to help colleagues when needed.',
            ],
            'Professionalism' => [
                'Maintains a professional attitude and appearance.',
                'Follows workplace policies, rules, and code of conduct.',
            ],
            'Initiative' => [
                'Takes initiative on tasks without needing to be told.',
                'Proposes ideas or improvements when appropriate.',
            ],
            'Technical Skills' => [
                'Demonstrates the technical/job-specific skills required for the role.',
                'Applies theoretical knowledge effectively to practical tasks.',
            ],
            'Adaptability' => [
                'Adjusts well to new tasks, tools, or changes in instructions.',
                'Handles feedback and correction constructively.',
            ],
            'Responsibility' => [
                'Takes ownership of assigned duties and follows through.',
                'Handles company resources and information responsibly.',
            ],
        ];

        $sortOrder = 1;

        foreach ($categories as $category => $labels) {
            foreach ($labels as $label) {
                EvaluationCriteria::updateOrCreate(
                    ['category' => $category, 'label' => $label],
                    [
                        'description' => null,
                        'is_active' => true,
                        'sort_order' => $sortOrder,
                    ],
                );

                $sortOrder++;
            }
        }
    }
}
