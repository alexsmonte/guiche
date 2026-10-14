<?php

declare(strict_types=1);

return [
    'sql' => [
        'profissionais_por_tratamento' => [
            [
                'profissional_id' => 101,
                'id_profissional' => 101,
                'nome_completo' => 'Dra. Ana Exemplo',
                'nome_profissional' => 'Dra. Ana Exemplo',
                'servico_id' => 201,
                'servico_nome' => 'Avaliação',
                'tratamento' => 'Avaliação',
                'convenio' => 'Convênio Exemplo',
                'dias' => 'segunda-feira, quarta-feira',
                'profissionais' => '[ profissional_id: 101 nome_completo: Dra. Ana Exemplo ]',
            ],
            [
                'profissional_id' => 102,
                'id_profissional' => 102,
                'nome_completo' => 'Dr. Bruno Exemplo',
                'nome_profissional' => 'Dr. Bruno Exemplo',
                'servico_id' => 201,
                'servico_nome' => 'Avaliação',
                'tratamento' => 'Avaliação',
                'convenio' => 'Convênio Exemplo',
                'dias' => 'terça-feira, quinta-feira',
                'profissionais' => '[ profissional_id: 102 nome_completo: Dr. Bruno Exemplo ]',
            ],
        ],
        'sp_consulta_profissional' => [
            [
                'profissional_id' => 101,
                'id_profissional' => 101,
                'nome_completo' => 'Dra. Ana Exemplo',
                'nome_profissional' => 'Dra. Ana Exemplo',
                'profissionais' => '[ profissional_id: 101 nome_completo: Dra. Ana Exemplo ]',
            ],
        ],
        'horarios_disponiveis_por_turno' => [
            ['horario_disponivel' => '15/01/2030 terça-feira 09:00'],
            ['horario_disponivel' => '15/01/2030 terça-feira 09:30'],
            ['horario_disponivel' => '15/01/2030 terça-feira 10:30'],
            ['horario_disponivel' => '16/01/2030 quarta-feira 14:00'],
        ],
        'fake_consultar_paciente' => [
            [
                'resposta_ia' => "paciente_id: 9001\nNome do paciente: Paciente Exemplo\n\n",
                'paciente_id' => 9001,
                'nome' => 'Paciente Exemplo',
                'cpf' => '00000000000',
            ],
        ],
    ],
    'procedure_parameters' => [
        'fake_consultar_paciente' => [
            ['PARAMETER_NAME' => 'p_id_empresa', 'ORDINAL_POSITION' => 1, 'DATA_TYPE' => 'int', 'PARAMETER_MODE' => 'IN'],
            ['PARAMETER_NAME' => 'p_cpf', 'ORDINAL_POSITION' => 2, 'DATA_TYPE' => 'varchar', 'PARAMETER_MODE' => 'IN'],
        ],
    ],
    'http' => [
        'status' => 200,
        'json' => [
            'result' => 'success',
            'message' => 'Agendamento fake realizado com sucesso.',
            'agendamento_id' => 'fake-agendamento-001',
            'novoAgendamento' => true,
        ],
    ],
];
